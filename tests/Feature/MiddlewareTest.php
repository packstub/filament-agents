<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\ToolResult;
use Packstub\Agents\AgentsPlugin;
use Packstub\Agents\Ai\Middleware\AttachContext;
use Packstub\Agents\Ai\Middleware\EnforceBudget;
use Packstub\Agents\Exceptions\TurnRefused;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Filament\Pages\Chat;
use Packstub\Agents\Jobs\RunAgentTurn;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentLimits;
use Packstub\Agents\Support\AgentTurns;
use Packstub\Agents\Tests\Fixtures\Middleware\RecordsTurns;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(fn () => RecordsTurns::reset());

/** Queue a question from the chat page and hand back the job a worker would pick up. */
function queuedTurn(string $prompt): array
{
    livewire(Chat::class)->call('send', $prompt);

    $turn = AgentTurn::query()->latest('id')->firstOrFail();
    $job = Queue::pushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $turn->id)->first();

    return [$turn, $job];
}

it('runs the app middleware on every turn: the prompt on the way in, the answer on the way out', function () {
    actingAs($this->user());
    Queue::fake();
    config(['packstub-agents.middleware' => [RecordsTurns::class]]);
    RecordsTurns::$suffix = 'Answer in one line.';

    expect(Agents::agent()->middleware())->toHaveCount(3)
        ->and(Agents::agent()->middleware()[0])->toBeInstanceOf(EnforceBudget::class)
        ->and(Agents::agent()->middleware()[1])->toBeInstanceOf(RecordsTurns::class)
        ->and(Agents::agent()->middleware()[2])->toBeInstanceOf(AttachContext::class);

    [$turn, $job] = queuedTurn('How many widgets are live?');

    // Nothing ran yet: the middleware sees a turn only when it runs, not when it is queued.
    expect(RecordsTurns::$prompts)->toBe([]);

    WidgetAgent::fake(['Two widgets are live.']);
    $job->handle(app(AgentTurns::class));

    expect($turn->fresh()->status)->toBe(AgentTurn::DONE)
        ->and(RecordsTurns::$prompts)->toBe(['How many widgets are live?'])
        ->and(RecordsTurns::$answers)->toBe(['Two widgets are live.']);

    // The middleware revised the prompt for the model; the transcript keeps what the person typed.
    expect(ConversationMessage::query()->where('role', 'user')->value('content'))->toBe('How many widgets are live?');
});

it('attaches the dynamic block to the question on every step, after the app middleware, and leaves an approval turn alone', function () {
    actingAs($this->user(['name' => 'Grace Hopper']));
    $agent = Agents::agent();
    $middleware = new AttachContext($agent);
    $step = fn (int $number, array $messages) => new PendingStep($number, false, 'anthropic', 'claude-opus-5', $agent->instructions(), $messages, [], null, null, invocationId: 'inv-1');
    $through = fn (PendingStep $pending) => $middleware->handle($pending, fn (PendingStep $sent) => $sent);
    $results = new ToolResultMessage(collect([new ToolResult('call-1', 'list-widgets', [], '[]')]));

    $sent = $through($step(0, [new UserMessage('How many widgets are live?')]));

    // The block leads, the question closes the message; the system prompt stays static.
    expect($sent->messages[0]->content)->toStartWith('## Now')
        ->toContain('Person asking: Grace Hopper')
        ->toEndWith("\n\nHow many widgets are live?")
        ->and($sent->instructions)->not->toContain('## Now');

    // The tool step of the same turn reads the same question, block included; the tool results stay as they are.
    $next = $through($step(1, [new UserMessage('How many widgets are live?'), new Message('assistant', ''), $results]));
    expect($next->messages[0]->content)->toBe($sent->messages[0]->content)
        ->and($next->messages[2])->toBe($results);

    // An approval turn starts on a tool result, with no question to carry the block: the messages go through unchanged.
    $resume = $step(0, [new UserMessage('Rename Alpha.'), new Message('assistant', ''), $results]);
    expect((new AttachContext($agent))->handle($resume, fn (PendingStep $sent) => $sent))->toBe($resume);
});

it('lets a middleware refuse a turn: the question keeps a Retry, nothing is stored or billed', function () {
    actingAs($this->user());
    Queue::fake();
    config(['packstub-agents.middleware' => [function (PendingStep $step, Closure $next) {
        throw new TurnRefused('Not during the audit.');
    }]]);

    [$turn, $job] = queuedTurn('Retire every widget.');

    WidgetAgent::fake(['Should never be produced.']);
    $job->handle(app(AgentTurns::class));

    expect($turn->fresh()->status)->toBe(AgentTurn::FAILED)
        ->and($turn->fresh()->error)->toBe('Not during the audit.')
        ->and(ConversationMessage::query()->where('role', 'assistant')->exists())->toBeFalse();

    livewire(Chat::class, ['conversation' => $turn->conversation_id])
        ->assertSee('Not during the audit.')
        ->assertSee(__('Retry'));
});

it('enforces the budget when the turn runs, whatever queued it', function () {
    $user = $this->user();
    actingAs($user);
    Queue::fake();
    config(['packstub-agents.limits.turns_per_day' => 1]);
    AgentLimits::flush();

    // Passes the page's check (nothing answered yet today) and is queued…
    [$turn, $job] = queuedTurn('How many widgets are live?');

    // …but by the time it runs the day's answer was given elsewhere.
    $other = Conversation::query()->create(['id' => (string) Str::uuid(), 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id, 'title' => 'Earlier']);
    ConversationMessage::query()->create([
        'id' => (string) Str::uuid(), 'conversation_id' => $other->id, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'agent' => WidgetAgent::class, 'role' => 'assistant', 'content' => 'Earlier answer.', 'attachments' => [], 'meta' => [], 'steps' => [], 'status' => 'completed',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
    ]);

    WidgetAgent::fake(['Should never be produced.']);
    $job->handle(app(AgentTurns::class));

    expect($turn->fresh()->status)->toBe(AgentTurn::FAILED)
        ->and($turn->fresh()->error)->toContain("today's limit")
        ->and(ConversationMessage::query()->where('content', 'Should never be produced.')->exists())->toBeFalse();
});

it('counts a turn against the per-minute limit when it runs, not when it is queued', function () {
    $user = $this->user();
    actingAs($user);
    Queue::fake();
    $key = 'agent-turns:central:'.$user->id;
    RateLimiter::clear($key);

    [$turn, $job] = queuedTurn('How many widgets are live?');
    expect(RateLimiter::attempts($key))->toBe(0);

    WidgetAgent::fake(['Two widgets are live.']);
    $job->handle(app(AgentTurns::class));

    expect(RateLimiter::attempts($key))->toBe(1)
        ->and($turn->fresh()->status)->toBe(AgentTurn::DONE);
});

it('takes the middleware list from the plugin, after the ones in config', function () {
    $closure = fn (PendingStep $step, Closure $next) => $next($step);
    config(['packstub-agents.middleware' => [RecordsTurns::class]]);

    $plugin = AgentsPlugin::make()->middleware([$closure]);
    $plugin->register(Filament::getPanel('admin'));

    expect(Agents::middleware())->toHaveCount(2)
        ->and(Agents::middleware()[0])->toBeInstanceOf(RecordsTurns::class)
        ->and(Agents::middleware()[1])->toBe($closure);

    Agents::useMiddleware([]);
});
