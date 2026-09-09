<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Prompts\AgentPrompt;
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

it('attaches the dynamic block to the question, after the app middleware, and leaves an approval turn alone', function () {
    actingAs($this->user(['name' => 'Grace Hopper']));
    $agent = Agents::agent();
    $provider = app(AiManager::class)->textProviderFor($agent, 'anthropic');
    $through = fn (AgentPrompt $prompt) => (new AttachContext)->handle($prompt, fn (AgentPrompt $sent) => $sent);

    $sent = $through(new AgentPrompt($agent, 'How many widgets are live?', [], $provider, 'claude-opus-5'));

    // The block leads, the question closes the prompt; the system prompt stays static.
    expect($sent->prompt)->toStartWith('## Now')
        ->toContain('Person asking: Grace Hopper')
        ->toEndWith("\n\nHow many widgets are live?")
        ->and($agent->instructions())->not->toContain('## Now');

    // An approval turn has no question to carry the block: the prompt goes through unchanged.
    $decisions = new AgentPrompt($agent, '', [], $provider, 'claude-opus-5', approvalDecisions: Decisions::from(['call-1' => Decision::approve()]));
    expect($through($decisions))->toBe($decisions);
});

it('lets a middleware refuse a turn: the question keeps a Retry, nothing is stored or billed', function () {
    actingAs($this->user());
    Queue::fake();
    config(['packstub-agents.middleware' => [function (AgentPrompt $prompt, Closure $next) {
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
        'agent' => WidgetAgent::class, 'role' => 'assistant', 'content' => 'Earlier answer.', 'attachments' => [], 'meta' => [], 'tool_calls' => [], 'tool_results' => [],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10],
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
    $closure = fn (AgentPrompt $prompt, Closure $next) => $next($prompt);
    config(['packstub-agents.middleware' => [RecordsTurns::class]]);

    $plugin = AgentsPlugin::make()->middleware([$closure]);
    $plugin->register(Filament::getPanel('admin'));

    expect(Agents::middleware())->toHaveCount(2)
        ->and(Agents::middleware()[0])->toBeInstanceOf(RecordsTurns::class)
        ->and(Agents::middleware()[1])->toBe($closure);

    Agents::useMiddleware([]);
});
