<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Models\Conversation;
use Packstub\Agents\Filament\Pages\Chat;
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

it('shows a context meter and continues a long chat in a new one that starts from a summary', function () {
    $user = $this->user();
    actingAs($user);
    config(['packstub-agents.history.max_tokens' => 1000, 'packstub-agents.history.notice_share' => 0.5]);

    $id = longChat($user, 6);

    WidgetAgent::fake(['Widgets 1 to 6 were live; the person kept asking for the live count.']);

    $component = livewire(Chat::class, ['conversation' => $id])
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
