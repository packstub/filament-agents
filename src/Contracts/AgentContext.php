<?php

namespace Packstub\Agents\Contracts;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Who is acting and where: the person, the guard they are signed in on, the
 * workspace and the locale of the current request — and how to put a queue
 * worker or an MCP request into that same shape. The package reads it
 * through Agents::context(); the container binds LaravelContext (a plain
 * Laravel app: the default guard, a workspace from a closure the app
 * registers) and AgentsPlugin swaps in FilamentContext (the panel, its guard
 * and its tenant, so a tenancy plugin keeps switching databases).
 */
interface AgentContext
{
    public function user(): ?Authenticatable;

    /** The auth guard the person is signed in on; the worker signs them in on the same one. */
    public function guard(): string;

    /** The workspace the request runs in, or null in a single-workspace app. */
    public function tenant(): ?Model;

    public function locale(): string;

    /**
     * What a queued turn needs to run as the request that sent it.
     *
     * @return array{panel: ?string, tenant: int|string|null, user: int|string|null, locale: string, guard: string}
     */
    public function capture(): array;

    /**
     * Put this runtime into the shape of a captured request: the workspace is
     * set, the person is signed in on the guard, the locale applied. `user`
     * may be an id (retrieved through the guard's provider) or an instance
     * (used as is — an MCP request passes the token's user). Returns the
     * closure that restores what was there before.
     *
     * @param  array{panel?: ?string, tenant?: int|string|null, user?: int|string|Authenticatable|null, locale?: ?string, guard?: ?string}  $context
     */
    public function enter(array $context): Closure;

    /** @return class-string<Model>|null the workspace model, or null without workspaces */
    public function tenantModel(): ?string;

    public function findTenant(int|string $key): ?Model;

    /** The workspace as it appears in the MCP path ("mcp/{tenant}"). */
    public function findTenantBySlug(string $slug): ?Model;

    public function canAccessTenant(Authenticatable $user, Model $tenant): bool;

    /**
     * The resources the assistant may show as live tables and use as page context.
     *
     * @return list<class-string<AgentResource>>
     */
    public function resourceClasses(): array;

    /** True inside the assistant's panel (chat, hooks and agent access show up); always false without one. */
    public function inPanel(): bool;
}
