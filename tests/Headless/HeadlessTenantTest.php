<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Prompts\AgentPrompt;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Jobs\RunAgentTurn;
use Packstub\Agents\Models\AgentLimit;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentBudget;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentLimits;
use Packstub\Agents\Support\AgentModels;
use Packstub\Agents\Support\AgentRuntime;
use Packstub\Agents\Support\AgentTurns;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Models\Team;
use Packstub\Agents\Tests\Fixtures\Tools\RetireWidget;
use Packstub\Agents\Tests\Fixtures\Tools\WhoAmI;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

use function Orchestra\Testbench\Pest\defineEnvironment;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

// A multi-workspace app without a panel: the MCP path carries the workspace.
defineEnvironment(fn ($app) => $app['config']->set('packstub-agents.mcp.path', 'mcp/{tenant}'));

beforeEach(function () {
    Agents::useAgent(WidgetAgent::class);
    Agents::useTools([WhoAmI::class, RetireWidget::class]);
    Agents::authorizeUsing(fn (string $ability) => Abilities::allows($ability));
    Agents::tenantModel(Team::class, 'slug');
});

it('resolves the workspace on the MCP path by slug, checks membership and holds the token to it', function () {
    $owner = $this->user(['locale' => 'ro']);
    $team = $this->team($owner, 'acme');
    $this->team($this->user(), 'globex');
    $mcp = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];
    $headers = fn (string $token) => ['Authorization' => 'Bearer '.$token] + $mcp;
    $whoAmI = fn (string $slug, string $token) => postJson('/mcp/'.$slug, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'who-am-i', 'arguments' => []]], $headers($token));

    expect(Agents::context()->tenantModel())->toBe(Team::class)
        ->and(Agents::context()->findTenantBySlug('acme')?->is($team))->toBeTrue()
        ->and(Agents::context()->findTenant($team->id)?->is($team))->toBeTrue()
        ->and(AgentLimit::tenantModel())->toBe(Team::class)
        ->and(AgentLimit::tenantName($team->id))->toBe('Acme');

    // The workspace is entered for the call: the tool sees it, the person and their locale.
    $token = $owner->createToken('laptop', ['read', 'tenant:acme'])->plainTextToken;
    $seen = json_decode($whoAmI('acme', $token)->assertOk()->assertJsonPath('result.isError', false)->json('result.content.0.text'), true);
    expect($seen)->toBe(['user' => $owner->id, 'guard' => 'sanctum', 'tenant' => 'acme', 'locale' => 'ro']);

    // Not a member there, and no such workspace at all.
    auth()->forgetGuards();
    $whoAmI('globex', $token)->assertNotFound();
    auth()->forgetGuards();
    $whoAmI('nowhere', $token)->assertNotFound();

    // A member's token minted for another workspace is refused on this one.
    auth()->forgetGuards();
    $foreign = $owner->createToken('desk', ['read', 'tenant:globex'])->plainTextToken;
    $whoAmI('acme', $foreign)->assertForbidden();
});

it('captures the workspace tenantUsing() resolves and restores it in the worker, through the enteringTenant() hook and its undo', function () {
    $owner = $this->user();
    $team = $this->team($owner, 'acme');
    $entered = [];
    Agents::tenantUsing(fn () => $team);
    Agents::enteringTenant(function (Model $tenant) use (&$entered): Closure {
        $entered[] = 'enter '.$tenant->slug;

        return function () use (&$entered, $tenant): void {
            $entered[] = 'leave '.$tenant->slug;
        };
    });
    actingAs($owner);
    Queue::fake();

    expect(Agents::tenant()?->is($team))->toBeTrue()
        ->and(AgentRuntime::capture())->toBe(['panel' => null, 'tenant' => $team->id, 'user' => $owner->id, 'locale' => 'en', 'guard' => 'web']);

    $seen = null;
    Agents::useMiddleware([function (AgentPrompt $prompt, Closure $next) use (&$seen) {
        $seen = [auth()->id(), Agents::tenant()?->slug];

        return $next($prompt);
    }]);

    $conversation = app(AgentConversationStore::class)->startConversation($owner, 'How many widgets are live?');
    $turn = app(AgentTurns::class)->enqueue($conversation, $owner, ['prompt' => 'How many widgets are live?'], null, 'auto', null);
    expect($turn->tenant)->toBe((string) $team->id);

    // The worker has no request: the resolver knows nothing, the turn's snapshot does.
    Agents::tenantUsing(fn () => null);
    auth()->logout();
    expect(Agents::tenant())->toBeNull();

    WidgetAgent::fake(['Two widgets are live.']);
    Queue::pushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $turn->id)->first()->handle(app(AgentTurns::class));

    expect($turn->fresh()->status)->toBe(AgentTurn::DONE)
        ->and($seen)->toBe([$owner->id, 'acme'])
        ->and($entered)->toBe(['enter acme', 'leave acme'])
        ->and(Agents::tenant())->toBeNull()
        ->and(auth()->user())->toBeNull();
});

it('keys budgets and limits by the workspace tenantUsing() resolves', function () {
    $owner = $this->user();
    $acme = $this->team($owner, 'acme');
    $globex = $this->team($owner, 'globex');
    actingAs($owner);
    $current = $acme;
    Agents::tenantUsing(function () use (&$current) {
        return $current;
    });

    AgentLimit::query()->create(['scope' => 'tenant', 'scope_id' => $acme->id, 'enabled' => false]);
    AgentLimit::query()->create(['scope' => 'tenant', 'scope_id' => $globex->id, 'turns_per_minute' => 1]);

    expect(AgentLimits::effective()['enabled'])->toBeFalse()
        ->and(AgentBudget::refusal('Hi'))->toBe(__(':name is switched off for this workspace.', ['name' => 'Ask Widgets']))
        ->and(AgentModels::enabled())->toBeFalse();

    // The burst limit is counted per workspace and person.
    $current = $globex;
    expect(AgentLimits::effective()['enabled'])->toBeTrue()
        ->and(AgentModels::enabled())->toBeTrue()
        ->and(AgentBudget::refusal('Hi'))->toBeNull();
    AgentBudget::hit();
    expect(AgentBudget::refusal('Hi'))->toBe(__('Too many questions in a row — give it a minute.'));

    $current = $this->team($owner, 'initech');
    expect(AgentBudget::refusal('Hi'))->toBeNull();
});
