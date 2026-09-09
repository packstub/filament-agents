<?php

use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Packstub\Agents\Filament\Pages\Chat;
use Packstub\Agents\Filament\Pages\Chats;
use Packstub\Agents\Models\AgentLimit;
use Packstub\Agents\Models\AgentMessageFeedback;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentBudget;
use Packstub\Agents\Support\AgentLimits;
use Packstub\Agents\Support\AgentModels;
use Packstub\Agents\Support\PageContext;
use Packstub\Agents\Tests\Fixtures\Filament\Resources\Widgets\WidgetResource;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;
use RuntimeException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

it('streams a chat into a persisted conversation and records feedback', function () {
    $user = $this->user();
    actingAs($user);

    WidgetAgent::fake(['Two widgets are live.']);

    livewire(Chat::class)
        ->set('prompt', 'How many widgets are live?')
        ->call('send');

    $conversation = Conversation::query()->where('participant_id', $user->id)->firstOrFail();
    $messages = ConversationMessage::query()->where('conversation_id', $conversation->id)->orderBy('created_at')->get();

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->role)->toBe('user')
        ->and($messages[1]->content)->toContain('Two widgets are live');

    livewire(Chat::class, ['conversation' => $conversation->id])
        ->assertSee('Two widgets are live')
        ->call('feedback', $messages[1]->id, 'up');

    expect(AgentMessageFeedback::query()->where('message_id', $messages[1]->id)->value('rating'))->toBe('up');

    livewire(Chats::class)->assertCanSeeTableRecords([$conversation]);

    // Another person never sees this conversation.
    actingAs($this->user());
    livewire(Chat::class, ['conversation' => $conversation->id])->assertNotFound();
});

it('hides the chat when no provider is configured or the workspace is switched off', function () {
    actingAs($this->user());

    expect(Chat::canAccess())->toBeTrue();

    config(['packstub-agents.enabled' => false]);
    expect(AgentModels::enabled())->toBeFalse()->and(Chat::canAccess())->toBeFalse()->and(Chats::canAccess())->toBeFalse();

    config(['packstub-agents.enabled' => null, 'ai.providers.anthropic.key' => null]);
    expect(AgentModels::enabled())->toBeFalse();

    config(['ai.providers.anthropic.key' => 'sk-platform', 'packstub-agents.provider' => 'anthropic']);
    $auto = config('packstub-agents.models.anthropic.auto');
    expect(AgentModels::enabled())->toBeTrue()
        ->and(AgentModels::resolve('auto'))->toMatchArray(['provider' => 'anthropic', 'model' => $auto['model'], 'effort' => $auto['effort']])
        ->and(AgentModels::options())->toHaveKeys(['auto', 'fast', 'deep']);

    AgentModels::remember('deep');
    expect(AgentModels::current())->toBe('deep');
});

it('stops a turn before the provider when the budget is spent', function () {
    $user = $this->user();
    actingAs($user);
    config(['packstub-agents.limits.turns_per_day' => 1, 'packstub-agents.limits.prompt_max_chars' => 50]);
    AgentLimits::flush();

    expect(AgentBudget::refusal(str_repeat('x', 51)))->toContain('too long')
        ->and(AgentBudget::refusal('hi'))->toBeNull();

    $conversation = Conversation::query()->create(['id' => (string) Str::uuid(), 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id, 'title' => 'Earlier']);
    ConversationMessage::query()->create([
        'id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'agent' => WidgetAgent::class, 'role' => 'assistant', 'content' => 'Earlier answer.', 'attachments' => [], 'meta' => [], 'tool_calls' => [], 'tool_results' => [],
        'usage' => ['prompt_tokens' => 600, 'completion_tokens' => 500],
    ]);

    expect(AgentBudget::turnsToday())->toBe(1)
        ->and(AgentBudget::tokensThisMonth())->toBe(1100)
        ->and(AgentBudget::refusal('hi'))->toContain("today's limit");

    // The question is recorded and the refusal read under it, with a Retry, like any other turn that got no answer.
    WidgetAgent::fake(['Should never be produced.']);
    $component = livewire(Chat::class)->set('prompt', 'hi')->call('send')->assertNotified();
    $refused = Conversation::query()->where('participant_id', $user->id)->where('title', 'hi')->firstOrFail();
    expect(ConversationMessage::query()->where('content', 'Should never be produced.')->exists())->toBeFalse()
        ->and(ConversationMessage::query()->where('conversation_id', $refused->id)->pluck('content')->all())->toBe(['hi'])
        ->and(AgentTurn::query()->where('conversation_id', $refused->id)->value('finish_reason'))->toBe('refused');

    livewire(Chat::class, ['conversation' => $refused->id])
        ->assertSee('hi')
        ->assertSee("today's limit")
        ->assertDontSee(__('The assistant could not answer.'))
        ->assertSee(__('Retry'));

    // Retry answers it once the limit allows.
    config(['packstub-agents.limits.turns_per_day' => 100]);
    AgentLimits::flush();
    livewire(Chat::class, ['conversation' => $refused->id])->call('retry')->assertNotNotified();
    expect(ConversationMessage::query()->where('conversation_id', $refused->id)->orderBy('id')->pluck('content')->all())->toBe(['hi', 'Should never be produced.']);
    ConversationMessage::query()->where('conversation_id', $refused->id)->delete();

    // The workspace's daily token budget, before the monthly one; a global row overrides config.
    config(['packstub-agents.limits.turns_per_day' => 100, 'packstub-agents.limits.tokens_per_day' => 1000]);
    AgentLimits::flush();
    expect(AgentBudget::refusal('hi'))->toContain('its AI budget for today')
        ->and(AgentBudget::summary())->toMatchArray(['tokens_today' => 1100, 'tokens_per_day' => 1000]);

    AgentLimit::query()->create(['scope' => 'global', 'tokens_per_day' => 2000]);
    AgentLimits::flush();
    expect(AgentBudget::refusal('hi'))->toBeNull();

    // A per-user monthly budget from the operator's rows.
    AgentLimit::query()->create(['scope' => 'user', 'scope_id' => (string) $user->id, 'user_tokens_per_month' => 500]);
    AgentLimits::flush();
    expect(AgentBudget::refusal('hi'))->toContain('your AI budget for the month')
        ->and(AgentBudget::summary())->toMatchArray(['user_tokens_month' => 1100, 'user_tokens_per_month' => 500]);

    AgentLimit::query()->where('scope', 'global')->update(['enabled' => false]);
    AgentLimits::flush();
    expect(AgentBudget::refusal('hi'))->toContain('Ask Widgets is switched off');
});

it('carries the record being viewed into the chat as page context', function () {
    actingAs($this->user());
    [$alpha] = $this->widgets();

    expect(PageContext::resolve('widgets/'.$alpha->id))->toBe(['label' => 'Widget Alpha', 'summary' => WidgetResource::agentSummary($alpha)])
        ->and(PageContext::resolve('widgets/999'))->toBeNull()
        ->and(PageContext::resolve('nope/1'))->toBeNull();

    // The topbar button on a record page links to the chat with the context; the chat shows what it is about.
    get(WidgetResource::getUrl('edit', ['record' => $alpha]))
        ->assertOk()
        ->assertSee('Ask Widgets')
        ->assertSee('context=widgets%2F'.$alpha->id, escape: false);

    livewire(Chat::class)->assertSee('New chat');

    $this->get(Chat::getUrl(['context' => 'widgets/'.$alpha->id]))->assertOk()->assertSee('About Widget Alpha');

    expect((new WidgetAgent(pageContext: 'widgets/'.$alpha->id))->dynamicInstructions())->toContain('opened this chat from Widget Alpha', '"name":"Alpha"');
});

it('keeps a decided proposal as a card and lets the model carry on after a rejection', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();

    $conversation = Conversation::query()->create(['id' => (string) Str::uuid(), 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id, 'title' => 'Renames']);
    $at = now()->subMinutes(5);
    // Rows carry time-ordered (v7) ids: laravel/ai and the history window take "the latest rows" by id.
    $message = function (array $attributes) use (&$at, $conversation, $user) {
        usleep(1100);

        return ConversationMessage::query()->create($attributes + [
            'id' => (string) Str::uuid7(), 'created_at' => $at = $at->addMinute(), 'conversation_id' => $conversation->id, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
            'agent' => WidgetAgent::class, 'role' => 'assistant', 'content' => '', 'attachments' => [], 'meta' => [], 'usage' => [],
        ]);
    };
    $call = fn (string $id, string $name) => ['id' => $id, 'name' => 'rename-widget', 'arguments' => ['id' => $alpha->id, 'name' => $name]];

    // The question the proposals answer (the history window is cut on a question, so it keeps all of these rows).
    $message(['role' => 'user', 'content' => 'Rename Alpha, please.', 'tool_calls' => [], 'tool_results' => []]);
    // Decided turns: laravel/ai empties the paused list and records the outcome with the tool results.
    $message(['tool_calls' => [$call('c1', 'Alpha II')], 'tool_results' => [$call('c1', 'Alpha II') + ['result' => 'The user rejected this tool call.', 'denied' => true]], 'approval_state' => ['pending' => []]]);
    $message(['tool_calls' => [$call('c2', 'Alpha III')], 'tool_results' => [$call('c2', 'Alpha III') + ['result' => '{"renamed":true}']], 'approval_state' => ['pending' => []]]);
    // A proposal still waiting for the person.
    $message(['tool_calls' => [$call('c3', 'Alpha IV')], 'tool_results' => [], 'approval_state' => ['pending' => ['c3' => ['name' => 'rename-widget']]]]);

    expect(Chat::writeToolNames())->toBe(['rename-widget']);

    livewire(Chat::class, ['conversation' => $conversation->id])
        ->assertSeeInOrder(['Rename Widget', 'Rejected', 'Rename Widget', 'Done', 'Rename Widget', 'Approve', 'Reject'])
        ->assertSee('Alpha II');

    // Rejecting hands the model a reason instead of a bare "no", so the turn continues and the model can answer.
    WidgetAgent::fake(['Understood, I left the name as it is.']);
    livewire(Chat::class, ['conversation' => $conversation->id])->call('decide', 'c3', false);

    WidgetAgent::assertPrompted(function ($prompt) {
        $decision = $prompt->approvalDecisions?->get('c3');

        return $decision?->isRejected() && $decision->result === Chat::rejectionResult();
    });
    expect(ConversationMessage::query()->where('conversation_id', $conversation->id)->where('content', 'like', '%left the name%')->exists())->toBeTrue();
});

it('keeps a question the provider could not answer and answers it on retry', function () {
    $user = $this->user();
    actingAs($user);

    WidgetAgent::fake([fn () => throw new RuntimeException('AI provider [gemini] is overloaded.')]);

    $component = livewire(Chat::class)
        ->call('send', 'How many widgets are live?')
        ->assertNotified()
        ->assertNoRedirect();

    $conversation = Conversation::query()->where('participant_id', $user->id)->firstOrFail();
    $component->assertSet('conversation', $conversation->id);
    $messages = ConversationMessage::query()->where('conversation_id', $conversation->id)->get();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->role)->toBe('user')
        ->and($messages[0]->content)->toBe('How many widgets are live?')
        ->and($conversation->title)->toBe('How many widgets are live?');

    $component = livewire(Chat::class, ['conversation' => $conversation->id])
        ->assertSee('How many widgets are live?')
        ->assertSee(__('The assistant could not answer.'))
        ->assertSee('AI provider [gemini] is overloaded.')
        ->assertSee(__('Retry'));

    expect($component->instance()->messages()->last()['unanswered'])->toBeTrue();

    WidgetAgent::fake(['Two widgets are live.']);
    $component->call('retry')->assertNotNotified();

    $messages = ConversationMessage::query()->where('conversation_id', $conversation->id)->orderBy('id')->get();

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->id)->toBe($messages->first()->id)
        ->and($messages[0]->role)->toBe('user')
        ->and($messages[1]->role)->toBe('assistant')
        ->and($messages[1]->content)->toContain('Two widgets are live');

    livewire(Chat::class, ['conversation' => $conversation->id])
        ->assertSee('Two widgets are live')
        ->assertDontSee(__('The assistant could not answer.'));

    // Nothing to retry once the question is answered.
    livewire(Chat::class, ['conversation' => $conversation->id])->call('retry');
    expect(ConversationMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(2);
});

it('records the question before the provider answers, without storing it twice', function () {
    $user = $this->user();
    actingAs($user);

    $seenHistory = null;
    WidgetAgent::fake([
        function (string $prompt) use (&$seenHistory) {
            // By now the question is on disk, and it is not repeated to the model as history.
            $seenHistory = ConversationMessage::query()->pluck('role')->all();

            return 'Draft, live and retired.';
        },
    ]);

    livewire(Chat::class)->call('send', 'Which statuses exist?')->assertNoRedirect();

    $conversation = Conversation::query()->where('participant_id', $user->id)->firstOrFail();
    $roles = ConversationMessage::query()->where('conversation_id', $conversation->id)->orderBy('id')->pluck('role')->all();

    expect($seenHistory)->toBe(['user'])
        ->and($roles)->toBe(['user', 'assistant']);

    // A follow-up in the same conversation: one user row, one assistant row again.
    WidgetAgent::fake(['Three of them.']);
    livewire(Chat::class, ['conversation' => $conversation->id])->set('prompt', 'How many?')->call('send');

    expect(ConversationMessage::query()->where('conversation_id', $conversation->id)->orderBy('id')->pluck('role')->all())
        ->toBe(['user', 'assistant', 'user', 'assistant']);
});

it('sends the question the composer passes along and keeps the page for a new chat', function () {
    $user = $this->user();
    actingAs($user);

    WidgetAgent::fake(['Hello!']);

    // The composer sends the text as an argument; a question from the URL is sent from the component's prompt.
    $component = livewire(Chat::class)
        ->set('prompt', 'stale draft')
        ->call('send', 'Hi there')
        ->assertNoRedirect()
        ->assertSet('prompt', '');

    $conversation = Conversation::query()->where('participant_id', $user->id)->firstOrFail();
    $component->assertSet('conversation', $conversation->id)->assertSee('Hi there')->assertSee('Hello!');

    expect(ConversationMessage::query()->where('conversation_id', $conversation->id)->where('role', 'user')->value('content'))->toBe('Hi there');

    livewire(Chat::class)->call('send', '   ');
    expect(Conversation::query()->where('participant_id', $user->id)->count())->toBe(1);
});

it('keeps the chat script off a click handler context for deferred work', function () {
    // Alpine evaluates a click handler with `this` bound to the clicked element, and Livewire's $wire follows that
    // element: once the morph has removed it (Retry, Regenerate, Approve, a queued question's Edit) $wire is a silent
    // no-op. So timers, the pump and continuations after an await must go through the root context (`self`).
    $script = file_get_contents(__DIR__.'/../../resources/js/agent-chat.js');

    expect($script)
        ->toContain('self = this')
        ->not->toMatch('/setTimeout\(\(\) => this\./')
        ->not->toContain('this.$wire.$refresh()')
        ->toContain('self.$wire.$refresh()')
        ->toContain('self.timer = setTimeout(() => self.poll(), delay)');
});
