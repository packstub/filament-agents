<?php

use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Packstub\Agents\Filament\Pages\Chat;
use Packstub\Agents\Filament\Pages\Chats;
use Packstub\Agents\Models\AgentLimit;
use Packstub\Agents\Models\AgentMessageFeedback;
use Packstub\Agents\Support\AgentBudget;
use Packstub\Agents\Support\AgentLimits;
use Packstub\Agents\Support\AgentModels;
use Packstub\Agents\Support\PageContext;
use Packstub\Agents\Tests\Fixtures\Filament\Resources\Widgets\WidgetResource;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

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

    config(['ai.providers.anthropic.key' => 'sk-platform']);
    expect(AgentModels::enabled())->toBeTrue()
        ->and(AgentModels::resolve('auto'))->toMatchArray(['provider' => 'anthropic', 'effort' => 'medium'])
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

    WidgetAgent::fake(['Should never be produced.']);
    livewire(Chat::class)->set('prompt', 'hi')->call('send')->assertNotified();
    expect(ConversationMessage::query()->where('content', 'Should never be produced.')->exists())->toBeFalse();

    // A per-user monthly budget from the operator's rows.
    config(['packstub-agents.limits.turns_per_day' => 100]);
    AgentLimit::query()->create(['scope' => 'user', 'scope_id' => (string) $user->id, 'user_tokens_per_month' => 500]);
    AgentLimits::flush();
    expect(AgentBudget::refusal('hi'))->toContain('your AI budget for the month')
        ->and(AgentBudget::summary())->toMatchArray(['user_tokens_month' => 1100, 'user_tokens_per_month' => 500]);

    AgentLimit::query()->create(['scope' => 'global', 'enabled' => false]);
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
