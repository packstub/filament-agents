# Without Filament

The engine of the package — the tools, the MCP server and its tokens, the turn job, budgets and limits — runs in a plain Laravel app. Filament is a suggestion, not a requirement: without it there is no chat page, no Agent access page and no operator pages, but every tool you write is served to Claude Code, Claude Desktop, Cursor or any MCP client with the same token checks, and a turn you queue yourself runs through the same job, middleware and budget.

## Install

```bash
composer require packstub/filament-agents
php artisan packstub-agents:install
```

The install command publishes `config/packstub-agents.php`, offers to run the migrations and scaffolds `app/Ai/Agents/Assistant.php`. The Sanctum setup is the same as in a panel app, see [Installation](installation.md#sanctum). There is no `filament:assets` and no theme `@source` to add.

## Register the agent and the tools

What `AgentsPlugin` does from a panel provider, a service provider does through the `Agents` facade:

```php
use App\Ai\Agents\Assistant;
use App\Mcp\Servers\AcmeServer;
use Packstub\Agents\Facades\Agents;

public function boot(): void
{
    Agents::useAgent(Assistant::class);
    Agents::useServer(AcmeServer::class);          // or Agents::useTools([SearchOrders::class, ConfirmOrder::class])
    Agents::authorizeUsing(fn (string $ability) => auth()->user()->can($ability));
    Agents::roleLabelUsing(fn () => auth()->user()->role?->getLabel());
}
```

- `useServer()` names the `AgentServer` subclass with the tool list; `config('packstub-agents.mcp.server')` does the same from config. Without either, the package's own server serves what `useTools()` was given, or its generic `draw-chart` tool alone.
- `useAgent()`, `authorizeUsing()`, `roleLabelUsing()`, `credentialsUsing()`, `useMiddleware()` and `limitsAuthorizeUsing()` mean what their `AgentsPlugin` counterparts mean, see [Configuration](configuration.md#agentsplugin).
- `name` comes from config (`AGENT_NAME`).

The scaffold commands know where they run: `packstub-agents:agent` and `packstub-agents:tool` print the service-provider registration instead of the plugin call.

## Who is acting and where

Every part of the package that needs the person, the guard, the workspace or the locale reads it from `Packstub\Agents\Contracts\AgentContext`, bound in the container. In a panel app `AgentsPlugin` binds `Packstub\Agents\Filament\FilamentContext` (the panel, its guard, its tenant); without one the binding is `Packstub\Agents\Support\Context\LaravelContext`:

- **The person** is whoever the guard in use holds — the default guard, or the one an auth middleware picked (`auth:sanctum` on the MCP endpoint). A turn records the guard it was asked on and the worker signs the person in on that same guard.
- **The workspace** is what `Agents::tenantUsing()` resolves; unregistered, the app is one workspace.
- **The locale** is the app's.

### Workspaces

Tell the package what a workspace is and how the current one is found:

```php
use App\Models\Team;
use Packstub\Agents\Facades\Agents;

Agents::tenantModel(Team::class, slugAttribute: 'slug');
Agents::tenantUsing(fn (): ?Team => auth()->user()?->currentTeam);

// Optional: what a database switch needs when a worker or an MCP request enters a workspace.
Agents::enteringTenant(function (Team $team): Closure {
    tenancy()->initialize($team);

    return fn () => tenancy()->end();
});
```

- `tenantModel()` is the model a worker finds a workspace by (its key is stored on the turn) and the MCP path names it by (`slug`, or the key when null).
- `tenantUsing()` resolves the current workspace of a request: budgets, limits, the prompt's workspace line and a workspace's own provider key (`credentialsUsing()`) are keyed by it.
- `enteringTenant()` runs when a queue worker or an MCP request *enters* a workspace that was found by key or slug — the place for a database switch or whatever your tenancy layer needs. Whatever it returns runs when the worker leaves the workspace again, so a long-lived worker does not stay on the last workspace's connection; return nothing when there is nothing to undo. In a panel app Filament's `TenantSet` event plays this role.
- Membership goes through your user model's `canAccessTenant(Model $tenant): bool` when it has one (the same method Filament's `HasTenants` asks for); without it every signed-in person may enter every workspace, so add the method as soon as you have more than one.

Put `{tenant}` in the MCP path (`'mcp/{tenant}'`) and a token is bound to the workspace it was minted for, exactly as in a panel: see [Tenancy](tenancy.md). Without a panel there is no Agent access page, so mint tokens yourself:

```php
$user->createToken('laptop', ['read', 'tenant:'.$team->slug], now()->addDays(30));
```

`read` alone is a read-only token, `write` runs write tools directly, `tool:{name}` limits it to named tools, `tenant:{slug}` binds it to a workspace. See [MCP clients](mcp-clients.md#the-agent-access-page).

## Routes

| Route | Where | Middleware |
| --- | --- | --- |
| `POST {mcp.path}` | the MCP endpoint | `mcp.middleware` (`throttle:60,1`, `auth:sanctum`, `AuthenticateAgent`) |
| `GET {chat.path}/chat/{conversation}/turn` | what a chat polls while an answer is produced: the answer so far, rendered, and a version stamp | `chat.middleware` (`['web', 'auth']`) |

The poll endpoint is registered by the package only when no panel registered its own; `chat.path` defaults to `agents`. It answers for the conversation's own participant.

## Running a turn yourself

There is no chat surface without Filament, but the turn machinery is there: start a conversation, queue a turn, poll it.

```php
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentTurns;

$conversation = app(AgentConversationStore::class)->startConversation($user, $question);
$turn = app(AgentTurns::class)->enqueue($conversation, $user, ['prompt' => $question], null, 'auto', null);
```

The `RunAgentTurn` job captures the context (`AgentContext::capture()`: the user, the guard, the workspace key, the locale) and restores it on the worker (`enter()`), so tools, ability checks and the prompt behave as they did in the request; the budget middleware refuses a turn that would overspend, and the `agent_turns` row records what it cost. `AgentTurns::active()`, `latest()` and the poll endpoint read the state back. Everything in [The assistant](assistant.md#how-a-turn-runs) about turns, Stop and the sync driver applies.

## What stays Filament-only

- The chat pages, the "Ask …" button, recent chats and page context.
- The Agent access page (tokens are minted with Sanctum directly instead).
- The operator's AI limits resource and AI turns page (the `agent_limits` rows and `AgentLimits` apply either way; edit the rows yourself).
- `show-table`, the embedded resource table and `AgentResource` discovery — a live table has no meaning outside a panel. `draw-chart` is served everywhere.
- `AgentsPlugin` and the fluent panel API; the `FilamentContext`.

Adding Filament later needs no code change on the package's side: register `AgentsPlugin` in a panel and the context, the assets and the pages come with it.
