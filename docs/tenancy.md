# Tenancy

The package works in a panel without tenancy: one workspace, the app's name, everyone's chats in one table. In a panel with tenancy it follows the workspace: the prompt names it, the budget counts it, the MCP endpoint carries it and tokens are bound to it.

## The MCP path

Put `{tenant}` in the path so an external agent works inside one workspace:

```php
// config/packstub-agents.php
'mcp' => [
    'path' => 'mcp/{tenant}',
],
```

`AuthenticateAgent` then, after `auth:sanctum`:

1. looks the workspace up by the panel's tenant slug attribute (`$panel->tenantSlugAttribute()`, or the key);
2. checks the person's membership with `canAccessTenant()` on the user model (404 otherwise);
3. checks that the token carries `tenant:{slug}` (403 when it was issued for another workspace, even when the person is a member there);
4. sets the panel's guard to the token's user and calls `Filament::setTenant($tenant)`.

That last step fires Filament's `TenantSet` event, the same one a page request fires, so anything that listens to it, [Filament Tenancy](https://packstub.dev/plugins/filament-tenancy) switching the database connection for instance, does exactly what it does for a page. Tools do not need to know they run over MCP.

The Agent access page shows the workspace's URL (`https://acme.test/mcp/acme`) and adds the `tenant:acme` ability to every token it mints there.

## A workspace's own key

A workspace can bring its own provider, key and preferred model. Tell the plugin where they come from:

```php
use Packstub\Agents\Ai\WorkspaceCredentials;

AgentsPlugin::make()->credentialsUsing(fn () => Filament::getTenant()
    ? new WorkspaceCredentials(
        provider: $settings->assistant_provider,   // 'anthropic' | 'openai'
        apiKey: $settings->assistant_api_key,
        model: $settings->assistant_model,         // a picker key: 'auto' | 'fast' | 'deep'
    )
    : null);
```

When the callback returns credentials with a key, the turn runs on that provider with that key (the key is swapped into laravel/ai's config for the request), and the chat is enabled for that workspace even when the platform has no key. Return `null` for "the platform's provider".

## Per-workspace limits

The operator's **AI limits** resource offers a "One workspace" scope in a panel with tenancy. A workspace row overrides the global one for that tenant; the **Assistant** switch on it turns the chat off for the workspace. See [Budgets and limits](budgets-and-limits.md).

## Database per tenant

By default the package runs its migrations from the vendor directory. In a database-per-tenant app, the tables belong to different databases:

| Table | Where | Why |
| --- | --- | --- |
| `agent_limits` | central | limits are the operator's |
| `agent_conversations`, `agent_conversation_messages`, `agent_message_feedback` | tenant | one workspace never sees another's chats, and an export or restore carries them along |

So:

```php
// config/packstub-agents.php
'run_migrations' => false,
'limits_connection' => env('AGENT_LIMITS_CONNECTION', 'central'),
```

```bash
php artisan vendor:publish --tag=packstub-agents-migrations
```

Keep `create_agent_limits_table` with your central migrations and move `create_agent_chat_tables` next to your tenant migrations. `AgentLimit` reads `limits_connection`, so the operator page works from any panel.

## What follows the workspace

| | Without tenancy | With tenancy |
| --- | --- | --- |
| Prompt | "Workspace: {app name}" | "Workspace: {tenant name}" |
| Per-minute rate limit key | `central` + user | tenant key + user |
| Turns per day, tokens per month | the whole app | the tenant's database (with database per tenant) |
| Tokens | `read` / `write` | `read` / `write` / `tenant:{slug}` |
| Chat pages, Ask button | always in the panel | only once a tenant is set |
