<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Monolog\Handler\TestHandler;
use Packstub\Agents\AgentsPlugin;
use Packstub\Agents\Exceptions\TurnRefused;
use Packstub\Agents\Filament\Pages\Chat;
use Packstub\Agents\Filament\Pages\TurnLog;
use Packstub\Agents\Jobs\RunAgentTurn;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentTurns;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

/** Queue a question from the chat page and hand back the turn and the job a worker would pick up. */
function queuedTurnFor(string $prompt): array
{
    livewire(Chat::class)->call('send', $prompt);

    $turn = AgentTurn::query()->latest('id')->firstOrFail();
    $job = Queue::pushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $turn->id)->first();

    return [$turn, $job];
}

/** A log channel the test can read back. */
function agentLog(): TestHandler
{
    config(['logging.channels.agents' => ['driver' => 'monolog', 'handler' => TestHandler::class], 'packstub-agents.log.channel' => 'agents']);

    return Log::channel('agents')->getLogger()->getHandlers()[0];
}

it('records what a turn cost and how it went on its row, and logs one line', function () {
    $user = $this->user();
    actingAs($user);
    Queue::fake();
    $log = agentLog();

    [$turn, $job] = queuedTurnFor('How many widgets are live?');

    // One tool round-trip, then the answer, with the token usage the provider reported for each step.
    WidgetAgent::fake([
        new ToolCall('call-1', 'list-widgets', ['filters' => []]),
        new TextResponse('Two are live.', new Usage(promptTokens: 120, completionTokens: 30, cacheReadInputTokens: 400, reasoningTokens: 5), new Meta('anthropic', 'claude-opus-5')),
    ]);
    $job->handle(app(AgentTurns::class));

    $turn->refresh();

    expect($turn->status)->toBe(AgentTurn::DONE)
        ->and($turn->provider)->toBe('anthropic')
        ->and($turn->model_name)->toBe('claude-opus-5')
        ->and($turn->usage)->toMatchArray(['prompt_tokens' => 120, 'completion_tokens' => 30, 'cache_read_input_tokens' => 400, 'reasoning_tokens' => 5])
        ->and($turn->tokensIn())->toBe(520)
        ->and($turn->tokensOut())->toBe(35)
        ->and($turn->tool_calls)->toBe(['list-widgets'])
        ->and($turn->duration_ms)->toBeInt()
        ->and($turn->finish_reason)->toBe('stop');

    expect($log->getRecords())->toHaveCount(1);
    $record = $log->getRecords()[0];
    expect($record->message)->toContain('Agent turn done: anthropic/claude-opus-5, 520 tokens in, 35 out, 1 tool call')
        ->and($record->context)->toMatchArray([
            'turn' => $turn->id, 'conversation' => $turn->conversation_id, 'user' => $user->id, 'status' => 'done',
            'provider' => 'anthropic', 'model' => 'claude-opus-5', 'prompt_tokens' => 120, 'completion_tokens' => 30,
            'tool_calls' => ['list-widgets'], 'finish_reason' => 'stop', 'error' => null,
        ]);

    // Without a channel nothing is logged; the row keeps the record either way.
    config(['packstub-agents.log.channel' => null]);
    [$second, $job] = queuedTurnFor('And drafts?');
    WidgetAgent::fake(['One.']);
    $job->handle(app(AgentTurns::class));

    expect($log->getRecords())->toHaveCount(1)
        ->and($second->fresh()->finish_reason)->toBe('stop');
});

it('records how a refused, a failed and a lost turn ended', function () {
    actingAs($this->user());
    Queue::fake();
    $log = agentLog();

    // Refused by a middleware: no provider was involved.
    config(['packstub-agents.middleware' => [fn (AgentPrompt $prompt, Closure $next) => throw new TurnRefused('Not now.')]]);
    [$refused, $job] = queuedTurnFor('First');
    $job->handle(app(AgentTurns::class));
    config(['packstub-agents.middleware' => []]);

    expect($refused->fresh())->toMatchArray(['status' => AgentTurn::FAILED, 'finish_reason' => 'refused', 'provider' => 'anthropic', 'tool_calls' => []])
        ->and($refused->fresh()->duration_ms)->toBeInt()
        ->and($log->getRecords()[0]->message)->toContain('Agent turn failed: anthropic/claude-opus-5')
        ->and($log->getRecords()[0]->context['error'])->toBe('Not now.');

    // The provider failed.
    [$failed, $job] = queuedTurnFor('Second');
    WidgetAgent::fake([fn () => throw new RuntimeException('AI provider [anthropic] is overloaded.')]);
    $job->handle(app(AgentTurns::class));

    expect($failed->fresh()->finish_reason)->toBe('failed')
        ->and($log->getRecords()[1]->context['finish_reason'])->toBe('failed');

    // The worker died: the job's failed() hook ends the turn with what it knows.
    [$lost, $job] = queuedTurnFor('Third');
    app(AgentTurns::class)->claim($lost);
    $job->failed(new RuntimeException('Job timed out.'));

    expect($lost->fresh())->toMatchArray(['status' => AgentTurn::FAILED, 'finish_reason' => 'failed', 'provider' => null])
        ->and($lost->fresh()->duration_ms)->toBeInt()
        ->and($log->getRecords())->toHaveCount(3);
});

it('lists the turns for the operator, gated like the limits', function () {
    $user = $this->user();
    $rows = collect([
        ['status' => AgentTurn::DONE, 'provider' => 'anthropic', 'model_name' => 'claude-opus-5', 'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 340, 'cache_read_input_tokens' => 0, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 0], 'tool_calls' => ['list-widgets', 'show-table'], 'duration_ms' => 4230, 'finish_reason' => 'stop'],
        ['status' => AgentTurn::FAILED, 'provider' => 'openai', 'model_name' => 'gpt-5', 'usage' => null, 'tool_calls' => [], 'duration_ms' => 800, 'finish_reason' => 'refused', 'error' => 'You used your AI budget for today.'],
    ])->map(fn (array $row) => AgentTurn::query()->create($row + [
        'id' => (string) Str::uuid7(), 'conversation_id' => (string) Str::uuid(), 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'input' => ['prompt' => 'Hi'], 'model' => 'auto', 'panel' => 'admin', 'started_at' => now(), 'finished_at' => now(),
    ]));

    actingAs($user);
    expect(TurnLog::canAccess())->toBeFalse();

    actingAs($this->user(['is_admin' => true]));
    expect(TurnLog::canAccess())->toBeTrue()
        ->and(Filament::getPanel('admin')->getPages())->toContain(TurnLog::class);

    livewire(TurnLog::class)
        ->assertCanSeeTableRecords($rows)
        ->assertSee('claude-opus-5')
        ->assertSee('1,200')
        ->assertSee('4.2 s')
        ->assertSee(__('Done'))
        ->assertSee('Refused')
        ->assertSee($user->email)
        ->filterTable('status', AgentTurn::FAILED)
        ->assertCanSeeTableRecords($rows->where('status', AgentTurn::FAILED))
        ->assertCanNotSeeTableRecords($rows->where('status', AgentTurn::DONE));

    // The page follows the limits resource unless told otherwise.
    $page = fn (AgentsPlugin $plugin) => (fn () => $this->turnLog ?? $this->limits)->call($plugin);
    expect($page(AgentsPlugin::make()->limits()))->toBeTrue()
        ->and($page(AgentsPlugin::make()->limits()->turnLog(false)))->toBeFalse()
        ->and($page(AgentsPlugin::make()->turnLog()))->toBeTrue()
        ->and($page(AgentsPlugin::make()))->toBeFalse();
});

it('prunes ended turns after keep_turns_days and keeps open ones', function () {
    $user = $this->user();
    $make = fn (string $status, int $daysAgo) => AgentTurn::query()->create([
        'id' => (string) Str::uuid7(), 'conversation_id' => (string) Str::uuid(), 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'status' => $status, 'input' => ['prompt' => 'Hi'], 'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
    ]);
    $old = $make(AgentTurn::DONE, 100);
    $recent = $make(AgentTurn::DONE, 10);
    $stuck = $make(AgentTurn::QUEUED, 100);

    config(['packstub-agents.chat.keep_turns_days' => null]);
    expect((new AgentTurn)->prunable()->count())->toBe(0);

    config(['packstub-agents.chat.keep_turns_days' => 90]);
    expect((new AgentTurn)->prunable()->count())->toBe(1);
    Artisan::call('model:prune', ['--model' => [AgentTurn::class]]);

    expect(AgentTurn::query()->pluck('id')->all())->toEqualCanonicalizing([$recent->id, $stuck->id]);
});
