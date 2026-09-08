<?php

namespace Packstub\Agents\Support;

use Closure;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Packstub\Agents\Facades\Agents;

/**
 * Puts a queue worker into the shape of the panel request a turn was sent
 * from: the panel is made current, the workspace is set (which fires
 * TenantSet, so a tenancy plugin switches the database as it would for a
 * page), the person is signed in on the panel's guard and the locale is
 * applied. Every tool, ability check and prompt line then behaves exactly as
 * it does in the chat. enter() returns a closure that restores what was
 * there before — a no-op inside a request, a clean-up on a long-lived worker.
 */
class AgentRuntime
{
    /**
     * @return array{panel: ?string, tenant: int|string|null, user: int|string|null, locale: string}
     */
    public static function capture(): array
    {
        return [
            'panel' => Filament::getCurrentPanel()?->getId() ?? Agents::panelId(),
            'tenant' => Filament::getTenant()?->getKey(),
            'user' => Filament::auth()->id() ?? auth()->id(),
            'locale' => app()->getLocale(),
        ];
    }

    /** @param  array{panel: ?string, tenant: int|string|null, user: int|string|null, locale: ?string}  $context */
    public static function enter(array $context): Closure
    {
        $previousPanel = Filament::getCurrentPanel();
        $previousTenant = Filament::getTenant();
        $previousLocale = app()->getLocale();
        $previousGuard = (string) config('auth.defaults.guard');

        $panel = self::panel($context['panel'] ?? null);
        if ($panel) {
            Filament::setCurrentPanel($panel);
        }

        $guard = Auth::guard($panel?->getAuthGuard() ?? config('auth.defaults.guard'));
        $previousUser = $guard->user();
        $user = ($context['user'] ?? null) !== null ? self::user($panel, $context['user']) : null;
        $userChanged = $user && $previousUser?->getAuthIdentifier() !== $user->getAuthIdentifier();

        if ($user) {
            $guard->setUser($user);
        }

        if ($panel) {
            Auth::shouldUse($panel->getAuthGuard());
        }

        $tenant = ($context['tenant'] ?? null) !== null ? self::tenant($panel, $context['tenant']) : null;
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

    protected static function panel(?string $id): ?Panel
    {
        if ($id && array_key_exists($id, Filament::getPanels())) {
            return Filament::getPanel($id);
        }

        return Agents::panel();
    }

    protected static function user(?Panel $panel, int|string $id): ?object
    {
        return Auth::guard($panel?->getAuthGuard() ?? config('auth.defaults.guard'))->getProvider()?->retrieveById($id);
    }

    protected static function tenant(?Panel $panel, int|string $key): ?Model
    {
        $model = $panel?->getTenantModel();

        return $model ? $model::query()->find($key) : null;
    }
}
