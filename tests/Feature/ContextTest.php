<?php

use Filament\Events\TenantSet;
use Filament\Facades\Filament;
use Illuminate\Foundation\Auth\User as PlainUser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\PendingStep;
use Packstub\Agents\Channels\Email\EmailChannel;
use Packstub\Agents\Channels\Email\InboundEmail;
use Packstub\Agents\Exceptions\WorkspaceAccessDenied;
use Packstub\Agents\Exceptions\WorkspaceNotFound;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Filament\Exceptions\PanelAccessDenied;
use Packstub\Agents\Filament\FilamentContext;
use Packstub\Agents\Jobs\RunAgentTurn;
use Packstub\Agents\Mcp\AgentServer;
use Packstub\Agents\Mcp\Tools\DrawChart;
use Packstub\Agents\Mcp\Tools\ShowTable;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentRun;
use Packstub\Agents\Support\AgentRuntime;
use Packstub\Agents\Support\AgentTurns;
use Packstub\Agents\Support\Installed;
use Packstub\Agents\Tests\Fixtures\Filament\Resources\Widgets\WidgetResource;
use Packstub\Agents\Tests\Fixtures\Models\Team;
use Packstub\Agents\Tests\Fixtures\Models\Widget;
use Packstub\Agents\Tests\Fixtures\Tools\WhoAmI;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

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

it('refuses to enter a workspace the person is not a member of, from a worker and from AgentRun', function () {
    $owner = $this->user();
    $acme = Team::query()->create(['owner_id' => $owner->id, 'name' => 'Acme', 'slug' => 'acme']);
    $globex = Team::query()->create(['owner_id' => $this->user()->id, 'name' => 'Globex', 'slug' => 'globex']);
    Filament::getPanel('admin')->tenant(Team::class, slugAttribute: 'slug');
    Event::fake([TenantSet::class]);

    expect(fn () => AgentRuntime::enter(['panel' => 'admin', 'tenant' => $globex->id, 'user' => $owner->id, 'locale' => 'de']))
        ->toThrow(WorkspaceAccessDenied::class, 'You are not a member of this workspace.')
        ->and(Filament::getTenant())->toBeNull()
        ->and(Filament::auth()->user())->toBeNull()
        ->and(app()->getLocale())->toBe('en');
    Event::assertNotDispatched(TenantSet::class);

    // A tenant named without a person: the one already signed in on the panel's guard is checked the same way.
    Filament::auth()->login($owner);
    expect(fn () => AgentRuntime::enter(['panel' => 'admin', 'tenant' => $globex->id]))
        ->toThrow(WorkspaceAccessDenied::class)
        ->and(Filament::getTenant())->toBeNull()
        ->and(Filament::auth()->user()?->is($owner))->toBeTrue();
    Event::assertNotDispatched(TenantSet::class);
    Filament::auth()->logout();

    $ran = 0;
    Agents::useMiddleware([function (PendingStep $step, Closure $next) use (&$ran) {
        $ran++;

        return $next($step);
    }]);
    WidgetAgent::fake(['Two.']);
    expect(fn () => AgentRun::as($owner)->in($globex)->ask('How many?'))->toThrow(WorkspaceAccessDenied::class)
        ->and($ran)->toBe(0)
        ->and(AgentTurn::query()->count())->toBe(0);

    // The person's own workspace is entered as before.
    $leave = AgentRuntime::enter(['panel' => 'admin', 'tenant' => $acme->id, 'user' => $owner->id]);
    expect(Filament::getTenant()?->is($acme))->toBeTrue();
    Event::assertDispatched(TenantSet::class, fn (TenantSet $event) => $event->getTenant()->is($acme));
    $leave();
});

it('refuses to enter the panel for a person its canAccessPanel() turns away, from a worker, AgentRun and the email channel', function () {
    $owner = $this->user();
    $acme = Team::query()->create(['owner_id' => $owner->id, 'name' => 'Acme', 'slug' => 'acme']);
    Filament::getPanel('admin')->tenant(Team::class, slugAttribute: 'slug');
    Event::fake([TenantSet::class]);

    // Suspended after the question was queued: still a member of Acme, no longer allowed on the panel.
    $owner->forceFill(['suspended_at' => now()])->save();

    expect(fn () => AgentRuntime::enter(['panel' => 'admin', 'tenant' => $acme->id, 'user' => $owner->id, 'locale' => 'de']))
        ->toThrow(PanelAccessDenied::class, 'You do not have access to this panel.')
        ->and(Filament::getTenant())->toBeNull()
        ->and(Filament::auth()->user())->toBeNull()
        ->and(app()->getLocale())->toBe('en');
    Event::assertNotDispatched(TenantSet::class);

    // The entry RunAgentTurn::refuse() makes right after, to record the failed turn (the panel, nobody, no
    // workspace), is let in with nobody signed in; the person is still refused.
    $leave = AgentRuntime::enter(['panel' => 'admin', 'tenant' => null, 'user' => null]);
    expect(Filament::getCurrentPanel()?->getId())->toBe('admin')->and(Filament::auth()->user())->toBeNull();
    $leave();
    expect(fn () => AgentRuntime::enter(['panel' => 'admin', 'user' => $owner->id]))->toThrow(PanelAccessDenied::class);

    $ran = 0;
    Agents::useMiddleware([function (PendingStep $step, Closure $next) use (&$ran) {
        $ran++;

        return $next($step);
    }]);
    WidgetAgent::fake(['Two.']);
    expect(fn () => AgentRun::as($owner)->in($acme)->ask('How many?'))->toThrow(PanelAccessDenied::class)
        ->and($ran)->toBe(0)
        ->and(AgentTurn::query()->count())->toBe(0);

    // The email channel drops the mail, as it does for a non-member: no answer, nothing sent.
    Mail::fake();
    $mail = new InboundEmail(from: $owner->email, subject: 'Widgets', text: 'How many?', tenant: 'acme');
    expect(EmailChannel::receive($mail))->toBeNull()->and($ran)->toBe(0);
    Mail::assertNothingSent();

    // A model without FilamentUser is let onto the panel, as Filament's Authenticate middleware does.
    $plain = (new PlainUser)->setTable('users')->newQuery()->findOrFail($this->user(['suspended_at' => now()])->id);
    $leave = AgentRuntime::enter(['panel' => 'admin', 'user' => $plain]);
    expect(Filament::auth()->user()?->is($plain))->toBeTrue();
    $leave();

    // Reinstated: entered as before.
    $owner->forceFill(['suspended_at' => null])->save();
    $leave = AgentRuntime::enter(['panel' => 'admin', 'tenant' => $acme->id, 'user' => $owner->id]);
    expect(Filament::getTenant()?->is($acme))->toBeTrue();
    $leave();
});

it('refuses a workspace key that matches nothing instead of running without a workspace, on every path', function () {
    $owner = $this->user();
    $acme = Team::query()->create(['owner_id' => $owner->id, 'name' => 'Acme', 'slug' => 'acme']);
    Filament::getPanel('admin')->tenant(Team::class, slugAttribute: 'slug');
    Event::fake([TenantSet::class]);
    $ran = 0;
    Agents::useMiddleware([function (PendingStep $step, Closure $next) use (&$ran) {
        $ran++;

        return $next($step);
    }]);
    WidgetAgent::fake(['Two.']);

    // The context: a key of a workspace that is gone is refused, with and without a person, and refused as a
    // WorkspaceAccessDenied too; the panel and the signed-in person are left as they were.
    expect(fn () => AgentRuntime::enter(['panel' => 'admin', 'tenant' => 999, 'user' => $owner->id, 'locale' => 'de']))
        ->toThrow(WorkspaceNotFound::class, 'This workspace no longer exists.')
        ->and(Filament::getTenant())->toBeNull()
        ->and(Filament::auth()->user())->toBeNull()
        ->and(Filament::getCurrentPanel()?->getId())->toBe('admin')
        ->and(app()->getLocale())->toBe('en');
    Filament::auth()->login($owner);
    expect(fn () => AgentRuntime::enter(['panel' => 'admin', 'tenant' => 999]))
        ->toThrow(WorkspaceAccessDenied::class, 'This workspace no longer exists.')
        ->and(Filament::getTenant())->toBeNull()
        ->and(Filament::auth()->user()?->is($owner))->toBeTrue();
    Filament::auth()->logout();
    Event::assertNotDispatched(TenantSet::class);

    // The worker: the workspace was deleted between the question and the turn — the turn fails with the line, nothing runs.
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::auth()->login($owner);
    Filament::setTenant($acme);
    Queue::fake();
    $conversation = app(AgentConversationStore::class)->startConversation($owner, 'Still there?');
    $turn = app(AgentTurns::class)->enqueue($conversation, $owner, ['prompt' => 'Still there?'], null, 'auto', null);
    Filament::auth()->logout();
    Filament::setTenant(null, isQuiet: true);
    Filament::setCurrentPanel(null);
    $acme->delete();

    Queue::pushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $turn->id)->first()->handle(app(AgentTurns::class));

    expect($turn->fresh()->status)->toBe(AgentTurn::FAILED)
        ->and($turn->fresh()->error)->toBe('This workspace no longer exists.')
        ->and($ran)->toBe(0)
        ->and(Filament::getTenant())->toBeNull()
        ->and(Filament::auth()->user())->toBeNull();
    Event::assertDispatchedTimes(TenantSet::class, 1); // the request that asked, not the worker
});

it('ignores the workspace key on a panel without tenancy, as before', function () {
    $owner = $this->user();

    $leave = AgentRuntime::enter(['panel' => 'admin', 'tenant' => 999, 'user' => $owner->id]);
    expect(Filament::getCurrentPanel()?->getId())->toBe('admin')
        ->and(Filament::getTenant())->toBeNull()
        ->and(Filament::auth()->user()?->is($owner))->toBeTrue();
    $leave();
});

it('refuses to enter a workspace with nobody acting, unless the caller says the system itself acts', function () {
    $owner = $this->user();
    $acme = Team::query()->create(['owner_id' => $owner->id, 'name' => 'Acme', 'slug' => 'acme']);
    $globex = Team::query()->create(['owner_id' => $this->user()->id, 'name' => 'Globex', 'slug' => 'globex']);
    Filament::getPanel('admin')->tenant(Team::class, slugAttribute: 'slug');
    Event::fake([TenantSet::class]);

    // A tenant with no user given and nobody signed in: nothing was checked, so nothing is entered.
    expect(fn () => AgentRuntime::enter(['panel' => 'admin', 'tenant' => $acme->id]))
        ->toThrow(WorkspaceAccessDenied::class, 'You are not a member of this workspace.')
        ->and(Filament::getTenant())->toBeNull()
        ->and(Filament::getCurrentPanel()?->getId())->toBe('admin')
        ->and(Filament::auth()->user())->toBeNull();
    Event::assertNotDispatched(TenantSet::class);

    // No workspace at all stays as it was: the panel is entered and needs nobody.
    $leave = AgentRuntime::enter(['panel' => 'admin', 'tenant' => null, 'locale' => 'de']);
    expect(Filament::getCurrentPanel()?->getId())->toBe('admin')->and(app()->getLocale())->toBe('de');
    $leave();
    expect(app()->getLocale())->toBe('en');

    // The app opts in for a job of its own: the system acts, the workspace is set (quietly: TenantSet names a
    // person, and there is none) and left on leaving.
    $leave = AgentRuntime::enter(['panel' => 'admin', 'tenant' => $acme->id, 'system' => true]);
    expect(Filament::getTenant()?->is($acme))->toBeTrue()->and(Filament::auth()->user())->toBeNull();
    Event::assertNotDispatched(TenantSet::class);
    $leave();
    expect(Filament::getTenant())->toBeNull();

    // system does not stand in for a membership check once someone acts: a non-member is still refused.
    expect(fn () => AgentRuntime::enter(['panel' => 'admin', 'tenant' => $globex->id, 'user' => $owner->id, 'system' => true]))
        ->toThrow(WorkspaceAccessDenied::class)
        ->and(Filament::getTenant())->toBeNull()
        ->and(Filament::auth()->user())->toBeNull();
    Event::assertNotDispatched(TenantSet::class);

    // A member with system set: entered as the person, TenantSet names them.
    $leave = AgentRuntime::enter(['panel' => 'admin', 'tenant' => $acme->id, 'user' => $owner->id, 'system' => true]);
    expect(Filament::getTenant()?->is($acme))->toBeTrue()->and(Filament::auth()->user()?->is($owner))->toBeTrue();
    Event::assertDispatched(TenantSet::class, fn (TenantSet $event) => $event->getTenant()->is($acme) && $event->getUser()->is($owner));
    $leave();
});

it('scopes the panel\'s resources to the workspace a worker enters', function () {
    $owner = $this->user();
    $acme = Team::query()->create(['owner_id' => $owner->id, 'name' => 'Acme', 'slug' => 'acme']);
    $globex = Team::query()->create(['owner_id' => $this->user()->id, 'name' => 'Globex', 'slug' => 'globex']);
    Widget::query()->create(['name' => 'Acme widget', 'team_id' => $acme->id]);
    Widget::query()->create(['name' => 'Globex widget', 'team_id' => $globex->id]);
    Filament::getPanel('admin')->tenant(Team::class, slugAttribute: 'slug');

    // The panel's boot (a request middleware) never ran here, as on a queue worker: entering the workspace
    // registers the tenancy scope, so a tool's query sees Acme's rows only.
    $leave = AgentRuntime::enter(['panel' => 'admin', 'tenant' => $acme->id, 'user' => $owner->id]);
    expect(WidgetResource::getEloquentQuery()->pluck('name')->all())->toBe(['Acme widget'])
        ->and(Widget::query()->create(['name' => 'New widget'])->team_id)->toBe($acme->id);

    $leave();
    expect(WidgetResource::getEloquentQuery()->count())->toBe(3);
});
