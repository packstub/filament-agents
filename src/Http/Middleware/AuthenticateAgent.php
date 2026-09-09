<?php

namespace Packstub\Agents\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Packstub\Agents\Facades\Agents;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts an MCP request into the same shape as a chat request, through the
 * AgentContext in use: when the path carries a {tenant}, the workspace is
 * looked up by its slug, checked against the person's memberships and
 * entered (in a panel that sets Filament's tenant and fires TenantSet, so a
 * tenancy plugin switches the database exactly as it would for a page); the
 * token's user is signed in on the context's guard and their locale applied.
 * Every tool then behaves exactly as it would in the chat. Runs after
 * auth:sanctum.
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

        $context = Agents::context();
        $route = $request->route();
        $tenant = null;

        if ($route?->hasParameter('tenant')) {
            $slug = (string) $route->parameter('tenant');
            $tenant = $context->findTenantBySlug($slug);

            if (! $tenant || ! $context->canAccessTenant($user, $tenant)) {
                return response()->json(['error' => 'Unknown workspace or no access.'], 404);
            }

            if ($user->currentAccessToken() && ! $user->tokenCan('tenant:'.$slug)) {
                return response()->json(['error' => 'This token was issued for another workspace.'], 403);
            }

            // The tenant is bound to the route parameter, so it must not leak into the MCP payload parsing.
            $route->forgetParameter('tenant');
        }

        // The token's own user instance, so currentAccessToken() keeps gating the tools; the guard has no session here.
        $context->enter([
            'tenant' => $tenant?->getKey(),
            'user' => $user,
            'locale' => filled($user->locale ?? null) ? (string) $user->locale : null,
        ]);

        return $next($request);
    }
}
