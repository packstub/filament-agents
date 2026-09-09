<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Models\Conversation;
use Packstub\Agents\AgentsPlugin;
use Packstub\Agents\Filament\Pages\Chat;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Models\ConversationSummary;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/** A conversation of $turns question/answer pairs; every answer called a tool that returned $resultBytes of JSON. */
function longChat(object $user, int $turns, int $resultBytes = 400): string
{
    $store = app(AgentConversationStore::class);
    $id = $store->startConversation($user, 'Long one');

    for ($i = 1; $i <= $turns; $i++) {
        usleep(1100);
        $store->storeQuestion($id, $user, WidgetAgent::class, "Question {$i}: how many widgets are live?");
        usleep(1100);
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $id,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->getKey(),
            'agent' => WidgetAgent::class,
            'role' => 'assistant',
            'content' => "Answer {$i}: there are {$i} widgets live.",
            'attachments' => '[]',
            'tool_calls' => json_encode([['id' => "call-{$i}", 'name' => 'list_widgets', 'arguments' => ['status' => 'live']]]),
            'tool_results' => json_encode([['id' => "call-{$i}", 'name' => 'list_widgets', 'arguments' => ['status' => 'live'], 'result' => str_repeat('x', $resultBytes)]]),
            'usage' => '[]',
            'meta' => '[]',
            'approval_state' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $id;
}

it('replays the recent turns that fit the budget, cut on a turn boundary, with older tool results pruned', function () {
    $user = $this->user();
    config(['packstub-agents.history.max_tokens' => 1000, 'packstub-agents.history.keep_tool_results_turns' => 1]);

    $id = longChat($user, 12);
    $store = app(AgentConversationStore::class);
    $messages = $store->getLatestConversationMessages($id, 40);

    // The oldest replayed message is a question, the newest an answer; not every turn fits.
    expect($messages->first()->role)->toBe(MessageRole::User)
        ->and($messages->first()->content)->toStartWith('Question')
        ->and($messages->last())->toBeInstanceOf(AssistantMessage::class)
        ->and($messages->last()->content)->toBe('Answer 12: there are 12 widgets live.')
        ->and($messages->filter(fn ($m) => $m->role === MessageRole::User)->count())->toBeLessThan(12)
        ->and(ConversationSummary::query()->count())->toBe(0);

    // The last turn keeps its tool result; the ones before carry a placeholder.
    $results = $messages->filter(fn ($m) => $m instanceof ToolResultMessage)->values();
    expect($results->last()->toolResults->first()->result)->toBe(str_repeat('x', 400))
        ->and($results->first()->toolResults->first()->result)->toContain('list_widgets result omitted from history');

    $usage = $store->contextUsage($id);
    expect($usage['share'])->toBe(1.0)->and($usage['summarized'])->toBeFalse();
});

it('folds what falls out of the window into a rolling summary the model reads first', function () {
    $user = $this->user();
    config(['packstub-agents.history.max_tokens' => 1000, 'packstub-agents.history.keep_tool_results_turns' => 1]);

    $id = longChat($user, 12);
    $store = app(AgentConversationStore::class);
    $prompts = [];
    $store->summarizeWith(function (string $prompt) use (&$prompts) {
        $prompts[] = $prompt;

        return 'Summary v'.count($prompts);
    });

    $messages = $store->getLatestConversationMessages($id, 40);

    expect($prompts)->toHaveCount(1)
        ->and($prompts[0])->toContain('Question 1:')->not->toContain('Existing summary')
        ->and($messages[0]->role)->toBe(MessageRole::User)
        ->and($messages[0]->content)->toContain('Summary v1')
        ->and($messages[1])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->role)->toBe(MessageRole::User);

    $summary = ConversationSummary::query()->where('conversation_id', $id)->firstOrFail();
    expect($summary->through_message_id)->not->toBeNull();

    // The window fits now: the next load does not summarize again.
    $store->getLatestConversationMessages($id, 40);
    expect($prompts)->toHaveCount(1);

    // More turns push older ones out; the summary is extended, not rewritten from scratch.
    for ($i = 13; $i <= 24; $i++) {
        usleep(1100);
        $store->storeQuestion($id, $user, WidgetAgent::class, "Question {$i}");
        usleep(1100);
        DB::table('agent_conversation_messages')->insert(['id' => (string) Str::uuid7(), 'conversation_id' => $id, 'agent' => WidgetAgent::class, 'role' => 'assistant', 'content' => str_repeat("Answer {$i}. ", 40), 'attachments' => '[]', 'tool_calls' => '[]', 'tool_results' => '[]', 'usage' => '[]', 'meta' => '[]', 'created_at' => now(), 'updated_at' => now()]);
    }
    $messages = $store->getLatestConversationMessages($id, 40);

    expect($prompts)->toHaveCount(2)
        ->and($prompts[1])->toContain("Existing summary:\nSummary v1")->not->toContain('Question 1:')
        ->and($messages[0]->content)->toContain('Summary v2')
        ->and($store->contextUsage($id)['summarized'])->toBeTrue();

    // A failing summarizer leaves the rows out for this turn and keeps the last good summary.
    for ($i = 25; $i <= 40; $i++) {
        usleep(1100);
        $store->storeQuestion($id, $user, WidgetAgent::class, str_repeat("Question {$i}. ", 40));
    }
    $store->summarizeWith(fn () => throw new RuntimeException('cheap model down'));
    $messages = $store->getLatestConversationMessages($id, 40);
    expect($messages[0]->content)->toContain('Summary v2')
        ->and(ConversationSummary::query()->where('conversation_id', $id)->value('content'))->toBe('Summary v2');
});

it('shows the context ring from meter_share on, with the breakdown and the turn totals behind it', function () {
    $user = $this->user();
    actingAs($user);

    $id = longChat($user, 6);
    $store = app(AgentConversationStore::class);

    // A short chat in a wide window: the composer is clean.
    config(['packstub-agents.history.max_tokens' => 24000]);
    $component = livewire(Chat::class, ['conversation' => $id])->assertDontSee('fi-chat-ring');
    expect($component->instance()->history()['meter'])->toBeFalse();

    // Lower the threshold: the ring shows, with the estimate and what fills the window.
    config(['packstub-agents.history.meter_share' => 0.01, 'packstub-agents.history.keep_tool_results_turns' => 1]);
    $usage = $store->contextUsage($id);
    expect($usage['breakdown']['questions'])->toBeGreaterThan(0)
        ->and($usage['breakdown']['answers'])->toBeGreaterThan(0)
        ->and($usage['breakdown']['tool_calls'])->toBeGreaterThan(0)
        ->and($usage['breakdown']['tool_results'])->toBeGreaterThanOrEqual(100) // the last exchange keeps its 400-byte result (and the call's JSON around it)
        ->and($usage['breakdown']['tool_results_pruned'])->toBe(5 * 40)
        ->and($usage['breakdown']['summary'])->toBe(0)
        ->and(array_sum($usage['breakdown']))->toBe($usage['tokens']);

    // Two recorded turns: the totals and the last turn's real context size.
    $turn = fn (array $usage, array $tools, int $ms) => AgentTurn::query()->create([
        'id' => (string) Str::uuid7(), 'conversation_id' => $id, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'status' => AgentTurn::DONE, 'input' => ['prompt' => 'Hi'], 'usage' => $usage, 'tool_calls' => $tools, 'duration_ms' => $ms, 'finish_reason' => 'stop',
    ]);
    $turn(['prompt_tokens' => 1000, 'completion_tokens' => 100], ['list_widgets'], 4000);
    usleep(1100);
    $turn(['prompt_tokens' => 2000, 'cache_read_input_tokens' => 500, 'completion_tokens' => 150, 'reasoning_tokens' => 50], ['list_widgets', 'show_table'], 62000);
    AgentTurn::query()->create(['id' => (string) Str::uuid7(), 'conversation_id' => $id, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id, 'status' => AgentTurn::QUEUED, 'input' => ['prompt' => 'Later']]);

    $component = livewire(Chat::class, ['conversation' => $id])
        ->assertSee('fi-chat-ring')
        ->assertSee(__('History window'))
        ->assertSee(__('This chat so far'))
        ->assertSee(__('Compress now'))
        ->assertSee(__('Continue in a new chat'))
        ->assertSee(__('The last question read :tokens tokens.', ['tokens' => '2,500']))
        ->assertSee('1.1 min');

    $history = $component->instance()->history();
    expect($history['meter'])->toBeTrue()
        ->and($history['notice'])->toBeFalse()
        ->and($history['turns'])->toBe(['count' => 2, 'tokens_in' => 3500, 'tokens_out' => 300, 'tool_calls' => 3, 'duration_ms' => 66000, 'last_tokens_in' => 2500]);
});

it('compresses a chat in place, keeping the last exchanges verbatim', function () {
    $user = $this->user();
    actingAs($user);
    config(['packstub-agents.history.max_tokens' => 24000, 'packstub-agents.history.compress_keep_turns' => 2]);

    $id = longChat($user, 6);
    $store = app(AgentConversationStore::class);
    $before = $store->contextUsage($id)['tokens'];

    WidgetAgent::fake(['Questions 1 to 4 asked for the live count; answers 1 to 4.']);

    livewire(Chat::class, ['conversation' => $id])->call('compressNow')->assertNotified(__('Older messages were compressed'));

    $summary = ConversationSummary::query()->where('conversation_id', $id)->firstOrFail();
    $messages = $store->getLatestConversationMessages($id, 40);
    $usage = $store->contextUsage($id);

    expect($summary->content)->toContain('Questions 1 to 4')
        ->and($summary->source_conversation_id)->toBeNull()
        ->and($messages)->toHaveCount(2 + 4 * 2) // the summary pair, then two exchanges of question, answer+call, result, answer
        ->and($messages[2]->content)->toBe('Question 5: how many widgets are live?')
        ->and($usage['summarized'])->toBeTrue()
        ->and($usage['tokens'])->toBeLessThan($before)
        ->and($usage['breakdown']['summary'])->toBeGreaterThan(0);

    // Nothing older than the kept exchanges: nothing to do, and the summary is untouched.
    expect($store->compactNow($id, fn () => 'never called', 2))->toBeFalse()
        ->and(ConversationSummary::query()->where('conversation_id', $id)->value('content'))->toBe($summary->content);

    // A failing summarizer is reported and keeps the summary so far.
    expect(fn () => $store->compactNow($id, fn () => throw new RuntimeException('cheap model down'), 1))->toThrow(RuntimeException::class);
    expect(ConversationSummary::query()->where('conversation_id', $id)->value('content'))->toBe($summary->content);

    // On the page the failure is a notification, and nothing is compressed while a turn runs.
    WidgetAgent::fake([]);
    AgentTurn::query()->create(['id' => (string) Str::uuid7(), 'conversation_id' => $id, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id, 'status' => AgentTurn::QUEUED, 'input' => ['prompt' => 'Later']]);
    livewire(Chat::class, ['conversation' => $id])->call('compressNow')->assertNotNotified();
});

it('mirrors the history settings of the plugin into config', function () {
    AgentsPlugin::make()->history(maxTokens: 12000, meterShare: 0.5, compressKeepTurns: 4)->register(Filament::getPanel('admin'));

    expect(config('packstub-agents.history.max_tokens'))->toBe(12000)
        ->and(config('packstub-agents.history.meter_share'))->toBe(0.5)
        ->and(config('packstub-agents.history.compress_keep_turns'))->toBe(4)
        ->and(config('packstub-agents.history.notice_share'))->toBe(0.7)
        ->and(AgentConversationStore::compressKeepTurns())->toBe(4)
        ->and(AgentConversationStore::meterShare())->toBe(0.5);
});

it('continues a long chat in a new one that starts from a summary', function () {
    $user = $this->user();
    actingAs($user);
    config(['packstub-agents.history.max_tokens' => 1000, 'packstub-agents.history.notice_share' => 0.5]);

    $id = longChat($user, 6);

    WidgetAgent::fake(['Widgets 1 to 6 were live; the person kept asking for the live count.']);

    $component = livewire(Chat::class, ['conversation' => $id])
        ->assertSee('fi-chat-ring-fill-high')
        ->assertSee(__('This chat is getting long — answers stay sharpest in a new one.'))
        ->assertSee(__('Continue in a new chat'));

    expect($component->instance()->history()['notice'])->toBeTrue();

    $component->call('continueInNewChat')->assertRedirect();

    $new = Conversation::query()->where('participant_id', $user->id)->whereKeyNot($id)->firstOrFail();
    $summary = ConversationSummary::query()->where('conversation_id', $new->id)->firstOrFail();

    expect($new->title)->toBe('Long one (continued)')
        ->and($summary->source_conversation_id)->toBe($id)
        ->and($summary->through_message_id)->toBeNull()
        ->and($summary->content)->toContain('Widgets 1 to 6 were live');

    livewire(Chat::class, ['conversation' => $new->id])
        ->assertSee(__('Continued from'))
        ->assertSee('Long one');

    // The new chat's model reads the summary and nothing else.
    $messages = app(AgentConversationStore::class)->getLatestConversationMessages($new->id, 40);
    expect($messages)->toHaveCount(2)->and($messages[0]->content)->toContain('Widgets 1 to 6 were live');
});
