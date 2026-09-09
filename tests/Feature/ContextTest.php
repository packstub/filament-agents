<?php

use Filament\Events\TenantSet;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Filament\FilamentContext;
use Packstub\Agents\Mcp\AgentServer;
use Packstub\Agents\Mcp\Tools\DrawChart;
use Packstub\Agents\Mcp\Tools\ShowTable;
use Packstub\Agents\Support\AgentRuntime;
use Packstub\Agents\Support\Installed;
use Packstub\Agents\Tests\Fixtures\Models\Team;
use Packstub\Agents\Tests\Fixtures\Tools\WhoAmI;

use function Orchestra\Testbench\Pest\defineEnvironment;
use function Pest\Laravel\postJson;

// The panel's MCP path carries the workspace for this file; the panel gets its tenancy per test.
defineEnvironment(fn ($app) => $app['config']->set('packstub-agents.mcp.path', 'mcp/{tenant}'));

it('binds the panel context once the plugin registered', function () {
    expect(Installed::filament())->toBeTrue()
        ->and(Agents::context())->toBeInstanceOf(FilamentContext::class)
        ->and(app(FilamentContext::class)->panel()?->getId())->toBe('admin')
        ->and(app(FilamentContext::class)->panelId())->toBe('admin')
        ->and(Agents::context()->guard())->toBe('web')
        ->and(Agents::context()->tenantModel())->toBeNull()
        ->and(Agents::context()->findTenantBySlug('acme'))->toBeNull();
});

it('gives the base server draw-chart, and show-table once the panel has agent resources', function () {
    Agents::useServer(AgentServer::class);

    expect(Agents::toolClasses())->toBe([DrawChart::class, ShowTable::class]);

    Agents::useResources([]);
    Agents::useTools([WhoAmI::class]);
    expect(Agents::toolClasses())->toBe([WhoAmI::class]);
});

it('fires TenantSet when a worker or an MCP request enters a workspace', function () {
    $owner = $this->user(['locale' => 'ro']);
    $team = Team::query()->create(['owner_id' => $owner->id, 'name' => 'Acme', 'slug' => 'acme']);
    Team::query()->create(['owner_id' => $this->user()->id, 'name' => 'Globex', 'slug' => 'globex']);
    Filament::getPanel('admin')->tenant(Team::class, slugAttribute: 'slug');
    Event::fake([TenantSet::class]);

    $context = Agents::context();
    expect($context->tenantModel())->toBe(Team::class)
        ->and($context->findTenantBySlug('acme')?->is($team))->toBeTrue()
        ->and($context->findTenant($team->id)?->is($team))->toBeTrue()
        ->and($context->canAccessTenant($owner, $team))->toBeTrue()
        ->and($context->canAccessTenant($this->user(), $team))->toBeFalse();

    // A worker: the panel is made current, the workspace set (TenantSet), the person signed in on the panel's guard.
    $leave = AgentRuntime::enter(['panel' => 'admin', 'tenant' => $team->id, 'user' => $owner->id, 'locale' => 'de']);

    expect(Filament::getTenant()?->is($team))->toBeTrue()
        ->and(Agents::tenant()?->is($team))->toBeTrue()
        ->and(Filament::auth()->user()?->is($owner))->toBeTrue()
        ->and(app()->getLocale())->toBe('de');
    Event::assertDispatchedTimes(TenantSet::class, 1);
    Event::assertDispatched(TenantSet::class, fn (TenantSet $event) => $event->getTenant()->is($team));

    $leave();
    expect(Filament::getTenant())->toBeNull()->and(Agents::tenant())->toBeNull()->and(app()->getLocale())->toBe('en');

    // An MCP request on the panel's tenant path: the same, with the token's user (the request leaves the panel state behind).
    $mcp = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];
    $token = $owner->createToken('laptop', ['read', 'tenant:acme'])->plainTextToken;
    $call = fn (string $slug) => postJson('/mcp/'.$slug, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list-widgets', 'arguments' => ['limit' => 1]]], ['Authorization' => 'Bearer '.$token] + $mcp);

    $call('acme')->assertOk()->assertJsonPath('result.isError', false);
    expect(Filament::getTenant()?->is($team))->toBeTrue()
        ->and(Filament::auth()->user()?->is($owner))->toBeTrue()
        ->and(auth()->getDefaultDriver())->toBe('web')
        ->and(app()->getLocale())->toBe('ro');
    Event::assertDispatchedTimes(TenantSet::class, 2);

    auth()->forgetGuards();
    $call('globex')->assertNotFound();
});
