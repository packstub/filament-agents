<?php

namespace Packstub\Agents\Filament;

use Closure;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Packstub\Agents\Contracts\AgentContext;
use Packstub\Agents\Contracts\AgentResource;
use Packstub\Agents\Facades\Agents;

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
        }

        $guardName = (string) ($context['guard'] ?? $panel?->getAuthGuard() ?? config('auth.defaults.guard'));
        $guard = Auth::guard($guardName);
        $previousUser = $guard->user();
        $given = $context['user'] ?? null;
        $user = $given instanceof Authenticatable ? $given : ($given !== null ? $guard->getProvider()?->retrieveById($given) : null);
        $userChanged = $user && $previousUser?->getAuthIdentifier() !== $user->getAuthIdentifier();

        if ($user) {
            $guard->setUser($user);
        }

        Auth::shouldUse($guardName);

        $key = $context['tenant'] ?? null;
        $tenant = $key !== null ? $this->findTenantIn($panel, $key) : null;
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
