<?php

namespace Packstub\Agents\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Packstub\Agents\Facades\Agents;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts an MCP request into the same shape as a panel request: the panel is
 * made current and, when the path carries a {tenant}, the workspace is
 * looked up by the panel's tenant slug, checked against the person's
 * memberships and set on Filament (which fires TenantSet, so a tenancy
 * plugin switches the database exactly as it would for a page). Every tool
 * then behaves exactly as it would in the chat. Runs after auth:sanctum.
 *
 * A token is minted for one workspace (it carries the "tenant:{slug}"
 * ability); using it on another workspace's URL is refused even when the
 * person is a member there.
 */
class AuthenticateAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $panel = Agents::panel();

        if ($panel) {
            Filament::setCurrentPanel($panel);
        }

        $route = $request->route();

        if ($route?->hasParameter('tenant')) {
            $slug = (string) $route->parameter('tenant');
            $tenant = self::findTenant($slug);

            if (! $tenant || ! method_exists($user, 'canAccessTenant') || ! $user->canAccessTenant($tenant)) {
                return response()->json(['error' => 'Unknown workspace or no access.'], 404);
            }

            if ($user->currentAccessToken() && ! $user->tokenCan('tenant:'.$slug)) {
                return response()->json(['error' => 'This token was issued for another workspace.'], 403);
            }

            // The panel's guard has no session here; give it the token's user so TenantSet carries who is acting
            // and everything that reads Filament::auth() behaves as on a page.
            Filament::auth()->setUser($user);
            Filament::setTenant($tenant);

            // The tenant is bound to the route parameter, so it must not leak into the MCP payload parsing.
            $route->forgetParameter('tenant');
        }

        if (filled($user->locale ?? null)) {
            app()->setLocale((string) $user->locale);
        }

        return $next($request);
    }

    protected static function findTenant(string $slug): ?object
    {
        $panel = Agents::panel();
        $model = $panel?->getTenantModel();

        if (! $model) {
            return null;
        }

        return $model::query()->where($panel->getTenantSlugAttribute() ?? (new $model)->getKeyName(), $slug)->first();
    }
}
