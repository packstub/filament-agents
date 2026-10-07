<?php

namespace Packstub\Agents\Filament;

use Closure;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Packstub\Agents\Contracts\AgentContext;
use Packstub\Agents\Contracts\AgentResource;
use Packstub\Agents\Exceptions\WorkspaceAccessDenied;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Filament\Exceptions\PanelAccessDenied;

/**
 * The context of a Filament panel: the panel the assistant lives in, its
 * guard, its tenant and its resources. enter() puts a queue worker or an MCP
 * request into the shape of a panel request — the panel is made current,
 * the workspace is set (which fires TenantSet, so a tenancy plugin switches
 * the database as it would for a page), the person is signed in on the
 * panel's guard and the locale is applied — and returns the closure that
 * restores what was there before: a no-op inside a request, a clean-up on a
 * long-lived worker. Bound by AgentsPlugin when it registers.
 */
class FilamentContext implements AgentContext
{
    /**
     * The panel and person of the last refused entry. RunAgentTurn::refuse() records the failed turn by entering the
     * same runtime again without the workspace; that one entry is let in with nobody signed in (the turn's person is
     * read by id), so the person refused by the panel never acts on it and the turn still ends with the line.
     *
     * @var array{panel: string, user: string}|null
     */
    protected ?array $refused = null;

    public function panelId(): ?string
    {
        return config('packstub-agents.panel');
    }

    /** The panel the assistant lives in: the current one when it matches, otherwise the registered one. */
    public function panel(): ?Panel
    {
        $current = Filament::getCurrentPanel();
        $id = $this->panelId();

        if ($current && (! $id || $current->getId() === $id)) {
            return $current;
        }

        return $id && array_key_exists($id, Filament::getPanels()) ? Filament::getPanel($id) : $current;
    }

    public function user(): ?Authenticatable
    {
        return Filament::auth()->user() ?? auth()->user();
    }

    public function guard(): string
    {
        return (string) ($this->panel()?->getAuthGuard() ?? config('auth.defaults.guard'));
    }

    /** The workspace the request runs in (Filament's tenant), if the panel has tenancy. */
    public function tenant(): ?Model
    {
        return Filament::getTenant();
    }

    public function locale(): string
    {
        return app()->getLocale();
    }

    public function capture(): array
    {
        return [
            'panel' => Filament::getCurrentPanel()?->getId() ?? $this->panelId(),
            'tenant' => Filament::getTenant()?->getKey(),
            'user' => Filament::auth()->id() ?? auth()->id(),
            'locale' => app()->getLocale(),
            'guard' => $this->guard(),
        ];
    }

    public function enter(array $context): Closure
    {
        $previousPanel = Filament::getCurrentPanel();
        $previousTenant = Filament::getTenant();
        $previousLocale = app()->getLocale();
        $previousGuard = (string) config('auth.defaults.guard');

        $panel = $this->resolvePanel($context['panel'] ?? null);
        if ($panel) {
            Filament::setCurrentPanel($panel);
            $this->scopeResourcesToTenant($panel);
        }

        $guardName = (string) ($context['guard'] ?? $panel?->getAuthGuard() ?? config('auth.defaults.guard'));
        $guard = Auth::guard($guardName);
        $previousUser = $guard->user();
        $given = $context['user'] ?? null;
        $user = $given instanceof Authenticatable ? $given : ($given !== null ? $guard->getProvider()?->retrieveById($given) : null);

        if ($this->isRefusalRecord($panel, $user, $context['tenant'] ?? null)) {
            $user = null;
        }

        $this->refused = null;
        $userChanged = $user && $previousUser?->getAuthIdentifier() !== $user->getAuthIdentifier();

        if ($user) {
            $guard->setUser($user);
        }

        Auth::shouldUse($guardName);

        $key = $context['tenant'] ?? null;
        $tenant = $key !== null ? $this->findTenantIn($panel, $key) : null;

        // Whoever acts on the panel and inside the workspace: the person given, else the one already signed in on the guard.
        $actor = $user ?? $previousUser;

        // The panel first, as Filament's Authenticate middleware asks it on a page (a model without FilamentUser
        // is let through, as there), then the workspace's membership.
        $denied = match (true) {
            $panel && $actor && ! $this->canAccessPanel($actor, $panel) => PanelAccessDenied::make(),
            $tenant && $actor && ! $this->canAccessTenant($actor, $tenant) => WorkspaceAccessDenied::make(),
            default => null,
        };

        if ($denied) {
            // Fail closed before the workspace is set: undo what was set so far and refuse.
            if ($userChanged) {
                $previousUser ? $guard->setUser($previousUser) : $guard->forgetUser();
            }

            Auth::shouldUse($previousGuard);
            Filament::setCurrentPanel($previousPanel);

            if ($denied instanceof PanelAccessDenied) {
                $this->refused = ['panel' => $panel->getId(), 'user' => (string) $actor->getAuthIdentifier()];
            }

            throw $denied;
        }

        if ($tenant && $previousTenant?->getKey() !== $tenant->getKey()) {
            Filament::setTenant($tenant);
        }

        if (filled($context['locale'] ?? null)) {
            app()->setLocale((string) $context['locale']);
        }

        return function () use ($previousPanel, $previousTenant, $previousLocale, $previousGuard, $previousUser, $userChanged, $guard, $tenant): void {
            if ($tenant && $previousTenant?->getKey() !== $tenant->getKey()) {
                Filament::setTenant($previousTenant, isQuiet: true);
            }

            if ($userChanged) {
                $previousUser ? $guard->setUser($previousUser) : $guard->forgetUser();
            }

            Auth::shouldUse($previousGuard);
            Filament::setCurrentPanel($previousPanel);
            app()->setLocale($previousLocale);
        };
    }

    /**
     * A panel's resources are scoped to its tenant by a global scope that Panel::boot() registers, and the
     * boot runs in a panel request only. A worker or an MCP request never gets it, so without this a
     * tool's query (`OrderResource::getEloquentQuery()`) would read every workspace's rows. Registered
     * once per model, the way the boot does it; a request that booted the panel already is left alone.
     */
    protected function scopeResourcesToTenant(Panel $panel): void
    {
        if (! $panel->hasTenancy()) {
            return;
        }

        foreach ($panel->getResources() as $resource) {
            if (! $resource::isScopedToTenant() || ! class_exists($model = $resource::getModel()) || $model::hasGlobalScope($panel->getTenancyScopeName())) {
                continue;
            }

            $resource::observeTenancyModelCreation($panel);
            $resource::registerTenancyModelGlobalScope($panel);
        }
    }

    public function tenantModel(): ?string
    {
        return $this->panel()?->getTenantModel();
    }

    public function findTenant(int|string $key): ?Model
    {
        return $this->findTenantIn($this->panel(), $key);
    }

    public function findTenantBySlug(string $slug): ?Model
    {
        $panel = $this->panel();
        $model = $panel?->getTenantModel();

        if (! $model) {
            return null;
        }

        return $model::query()->where($panel->getTenantSlugAttribute() ?? (new $model)->getKeyName(), $slug)->first();
    }

    public function canAccessTenant(Authenticatable $user, Model $tenant): bool
    {
        return method_exists($user, 'canAccessTenant') && (bool) $user->canAccessTenant($tenant);
    }

    /** The entry that records a turn refused by the panel: the same panel and person as the refusal, no workspace. */
    protected function isRefusalRecord(?Panel $panel, ?Authenticatable $user, int|string|null $tenant): bool
    {
        return $this->refused !== null
            && $tenant === null
            && $panel?->getId() === $this->refused['panel']
            && $user && (string) $user->getAuthIdentifier() === $this->refused['user'];
    }

    /** What Filament's Authenticate middleware asks on a page: canAccessPanel() when the model implements FilamentUser. */
    public function canAccessPanel(Authenticatable $user, Panel $panel): bool
    {
        return ! $user instanceof FilamentUser || $user->canAccessPanel($panel);
    }

    /** The list given to the plugin, or every resource of the panel that implements AgentResource. */
    public function resourceClasses(): array
    {
        if (($registered = Agents::registeredResources()) !== []) {
            return $registered;
        }

        $panel = $this->panel();

        return $panel ? array_values(array_filter($panel->getResources(), fn (string $r) => is_subclass_of($r, AgentResource::class))) : [];
    }

    /** True when the request is served inside the assistant's panel (chat, hooks and agent access show up). */
    public function inPanel(): bool
    {
        $panel = Filament::getCurrentPanel();

        if (! $panel || ($this->panelId() && $panel->getId() !== $this->panelId())) {
            return false;
        }

        return ! $panel->hasTenancy() || Filament::getTenant() !== null;
    }

    protected function resolvePanel(?string $id): ?Panel
    {
        if ($id && array_key_exists($id, Filament::getPanels())) {
            return Filament::getPanel($id);
        }

        return $this->panel();
    }

    protected function findTenantIn(?Panel $panel, int|string $key): ?Model
    {
        $model = $panel?->getTenantModel();

        return $model ? $model::query()->find($key) : null;
    }
}
