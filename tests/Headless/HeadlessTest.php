<?php

use Filament\FilamentServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Prompts\AgentPrompt;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Jobs\RunAgentTurn;
use Packstub\Agents\Mcp\Tools\DrawChart;
use Packstub\Agents\Models\AgentLimit;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentBudget;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentLimits;
use Packstub\Agents\Support\AgentModels;
use Packstub\Agents\Support\AgentRuntime;
use Packstub\Agents\Support\AgentTurns;
use Packstub\Agents\Support\Context\LaravelContext;
use Packstub\Agents\Support\Installed;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Models\Widget;
use Packstub\Agents\Tests\Fixtures\Tools\RetireWidget;
use Packstub\Agents\Tests\Fixtures\Tools\WhoAmI;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;
use Packstub\Agents\Tests\HeadlessTestCase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

// What a plain app's service provider does: the agent, how an ability is checked.
beforeEach(function () {
    Agents::useAgent(WidgetAgent::class);
    Agents::authorizeUsing(fn (string $ability) => Abilities::allows($ability));
});

it('boots without Filament, on the Laravel context', function () {
    expect($this)->toBeInstanceOf(HeadlessTestCase::class)
        ->and(app()->providerIsLoaded(FilamentServiceProvider::class))->toBeFalse()
        ->and(Installed::filament())->toBeFalse()
        ->and(Agents::context())->toBeInstanceOf(LaravelContext::class)
        ->and(Agents::inPanel())->toBeFalse()
        ->and(Agents::tenant())->toBeNull()
        ->and(Agents::resourceClasses())->toBe([])
        ->and(config('packstub-agents.panel'))->toBeNull();

    $user = $this->user();
    actingAs($user);

    expect(AgentRuntime::capture())->toBe(['panel' => null, 'tenant' => null, 'user' => $user->id, 'locale' => 'en', 'guard' => 'web']);
});

it('serves MCP with draw-chart as the only default tool, then the registered tools by token', function () {
    $user = $this->user();
    $this->widgets();
    $mcp = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];
    $headers = fn (string $token) => ['Authorization' => 'Bearer '.$token] + $mcp;
    $list = fn (string $token) => postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $headers($token))->assertOk()->json('result.tools.*.name');
    $call = fn (string $tool, array $args, string $token) => postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]], $headers($token));

    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 0, 'method' => 'tools/list'], $mcp)->assertStatus(401);

    // Nothing registered: the base server serves the generic tool alone — no show-table without a panel to embed a table in.
    expect(Agents::toolClasses())->toBe([DrawChart::class]);
    $read = $user->createToken('laptop', ['read'])->plainTextToken;
    expect($list($read))->toBe(['draw-chart']);

    // The app's own list, as a service provider registers it.
    Agents::useTools([WhoAmI::class, RetireWidget::class]);
    auth()->forgetGuards();
    expect($list($read))->toBe(['who-am-i']);

    // A tool runs as the token's user, on the guard the request authenticated on, in their locale.
    $user->forceFill(['locale' => 'de'])->save();
    auth()->forgetGuards();
    $seen = json_decode($call('who-am-i', [], $read)->assertOk()->assertJsonPath('result.isError', false)->json('result.content.0.text'), true);
    expect($seen)->toBe(['user' => $user->id, 'guard' => 'sanctum', 'tenant' => null, 'locale' => 'de']);

    auth()->forgetGuards();
    $call('retire-widget', ['id' => 1], $read)->assertOk()->assertJsonPath('error.message', 'Tool [retire-widget] not found.');

    // A scoped write token: the tools it names, and only those.
    auth()->forgetGuards();
    $scoped = $user->createToken('queue', ['read', 'write', 'tool:retire-widget'])->plainTextToken;
    expect($list($scoped))->toBe(['retire-widget']);
    auth()->forgetGuards();
    $call('retire-widget', ['id' => 1], $scoped)->assertOk()->assertJsonPath('result.isError', false);
    expect(Widget::query()->find(1)->status)->toBe('retired');
    auth()->forgetGuards();
    $call('who-am-i', [], $scoped)->assertOk()->assertJsonPath('error.message', 'Tool [who-am-i] not found.');
});

it('runs a queued turn as the person who asked, on the default guard, in their locale, and cleans up after', function () {
    $user = $this->user();
    actingAs($user);
    app()->setLocale('de');
    Queue::fake();

    $seen = null;
    Agents::useMiddleware([function (AgentPrompt $prompt, Closure $next) use (&$seen) {
        $seen = [auth()->id(), auth()->getDefaultDriver(), app()->getLocale(), Agents::tenant()];

        return $next($prompt);
    }]);

    $conversation = app(AgentConversationStore::class)->startConversation($user, 'How many widgets are live?');
    $turn = app(AgentTurns::class)->enqueue($conversation, $user, ['prompt' => 'How many widgets are live?'], null, 'auto', null);

    $job = Queue::pushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $turn->id)->first();
    expect($turn->panel)->toBeNull()
        ->and($job->runtime)->toBe(['panel' => null, 'tenant' => null, 'user' => $user->id, 'locale' => 'de']);

    // The worker knows nothing of the request.
    auth()->logout();
    app()->setLocale('en');
    expect(auth()->user())->toBeNull();

    WidgetAgent::fake(['Two widgets are live.']);
    $job->handle(app(AgentTurns::class));

    expect($turn->fresh()->status)->toBe(AgentTurn::DONE)
        ->and($seen)->toBe([$user->id, 'web', 'de', null])
        ->and(auth()->user())->toBeNull()
        ->and(app()->getLocale())->toBe('en');
});

it('enforces the budget and the operator rows in a single workspace', function () {
    $user = $this->user();
    actingAs($user);

    expect(AgentModels::enabled())->toBeTrue()->and(AgentBudget::refusal('Hi'))->toBeNull();

    // The operator's global row, and the burst limit keyed by "central" and the person.
    AgentLimit::query()->create(['scope' => 'global', 'turns_per_minute' => 1, 'prompt_max_chars' => 5]);
    AgentLimits::flush();

    expect(AgentBudget::refusal('far too long'))->toBe(__('That question is too long (max :n characters).', ['n' => 5]));
    AgentBudget::hit();
    expect(AgentBudget::refusal('Hi'))->toBe(__('Too many questions in a row — give it a minute.'));

    AgentLimit::query()->create(['scope' => 'user', 'scope_id' => $user->id, 'enabled' => false]);
    AgentLimits::flush();
    expect(AgentLimits::effective()['enabled'])->toBeFalse()
        ->and(AgentBudget::refusal('Hi'))->toBe(__(':name is switched off for this workspace.', ['name' => 'Ask Widgets']));
});

it('registers the poll endpoint under chat.path and chat.middleware for the conversation\'s own participant', function () {
    $user = $this->user();
    $other = $this->user();
    $conversation = app(AgentConversationStore::class)->startConversation($user, 'Hi');

    expect(Route::has('packstub-agents.turn'))->toBeTrue()
        ->and(route('packstub-agents.turn', ['conversation' => $conversation]))->toBe(url('/agents/chat/'.$conversation.'/turn'));

    $url = route('packstub-agents.turn', ['conversation' => $conversation]);

    getJson($url)->assertStatus(401);

    actingAs($other);
    getJson($url)->assertNotFound();

    actingAs($user);
    getJson($url)->assertOk()->assertJsonPath('active', null)->assertJsonStructure(['active', 'version']);
});

it('scaffolds the agent with a hint for a service provider, not a panel', function () {
    $path = app_path('Ai/Agents/Assistant.php');
    File::delete($path);

    try {
        $this->artisan('packstub-agents:agent')
            ->expectsOutputToContain('Agents::useAgent(\App\Ai\Agents\Assistant::class)')
            ->assertSuccessful();

        expect(File::get($path))->toContain('class Assistant extends Agent');
    } finally {
        File::delete($path);
    }
});
