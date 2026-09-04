# Configuration

`php artisan packstub-agents:install` publishes `config/packstub-agents.php`. Most values can also be set fluently on `AgentsPlugin` in the panel provider; the plugin mirrors those into the config so every runtime (queue, console, MCP requests) sees one truth.

## config/packstub-agents.php

| Key | Default | Env | What it does |
| --- | --- | --- | --- |
| `name` | `Assistant` | `AGENT_NAME` | how the assistant introduces itself; `AgentsPlugin::name()` overrides it |
| `panel` | `null` | | the panel the assistant lives in; set by the plugin when it registers |
| `provider` | `anthropic` | `AGENT_PROVIDER` | `anthropic` or `openai` (any laravel/ai text provider); the platform default, a workspace may bring its own |
| `enabled` | `null` | `AGENT_ENABLED` | `null` = enabled when a key exists for the provider; `false` hides the chat |
| `models` | see below | `AGENT_MODEL`, `AGENT_MODEL_FAST`, `AGENT_MODEL_DEEP` | the picker entries per provider: label, model, effort |
| `max_steps` | `12` | | tool round-trips one turn may take before the agent has to answer |
| `max_tokens` | `4096` | | answer length |
| `max_conversation_messages` | `40` | | how many earlier messages a long chat replays |
| `limits.*` | see [Budgets and limits](budgets-and-limits.md) | `AGENT_TURNS_PER_MINUTE` … | the platform ceiling |
| `limits_connection` | `null` | `AGENT_LIMITS_CONNECTION` | the connection of the `agent_limits` table (the central one in a database-per-tenant app) |
| `mcp.enabled` | `true` | `AGENT_MCP_ENABLED` | the MCP endpoint and the Agent access page |
| `mcp.path` | `mcp` | | the endpoint path; `mcp/{tenant}` in a panel with tenancy |
| `mcp.server` | `null` | | an `AgentServer` subclass; `null` = the package's server with the tools registered on the plugin |
| `mcp.middleware` | `['throttle:60,1', 'auth:sanctum', AuthenticateAgent::class]` | | the endpoint's middleware |
| `run_migrations` | `true` | | run the package migrations from the vendor directory; `false` to publish and split them |

The provider keys themselves live in laravel/ai's `config/ai.php` (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`).

### models

```php
'models' => [
    'anthropic' => [
        'auto' => ['label' => 'Auto', 'model' => env('AGENT_MODEL', 'claude-opus-5'), 'effort' => 'medium'],
        'fast' => ['label' => 'Fast', 'model' => env('AGENT_MODEL_FAST', 'claude-haiku-4-5'), 'effort' => null],
        'deep' => ['label' => 'Deep', 'model' => env('AGENT_MODEL_DEEP', 'claude-opus-5'), 'effort' => 'xhigh'],
    ],
    'openai' => [
        'auto' => ['label' => 'Auto', 'model' => env('AGENT_MODEL'), 'effort' => 'medium'],
        'fast' => ['label' => 'Fast', 'model' => env('AGENT_MODEL_FAST'), 'effort' => 'low'],
        'deep' => ['label' => 'Deep', 'model' => env('AGENT_MODEL_DEEP'), 'effort' => 'high'],
    ],
],
```

Rename, remove or add entries; the picker shows whatever is there. A `null` model resolves to the provider's smartest model (or cheapest for the `fast` key).

## AgentsPlugin

```php
use Packstub\Agents\AgentsPlugin;

AgentsPlugin::make()
    ->name('Ask Acme')
    ->agent(Assistant::class)
    ->server(AcmeServer::class)
    ->tools([SearchOrders::class, ShowTable::class])
    ->resources([OrderResource::class, CustomerResource::class])
    ->authorizeUsing(fn (string $ability): bool => auth()->user()->can($ability))
    ->roleLabelUsing(fn (): ?string => auth()->user()->role?->getLabel())
    ->credentialsUsing(fn (): ?WorkspaceCredentials => ...)
    ->chat(true)
    ->agentAccess(enabled: true, ability: 'setup.view', group: 'Setup')
    ->limits(enabled: true, authorize: fn (): bool => auth()->user()->is_admin)
    ->hideAskButtonOn(['*.pages.dashboard']);
```

| Method | |
| --- | --- |
| `name(string)` | how the assistant is called in the panel |
| `agent(class)` | your `Agent` subclass (default: the package's `DefaultAgent`) |
| `server(class)` | the `AgentServer` subclass with the tool list, name and instructions |
| `tools(array)` | the tool list when there is no server class |
| `resources(array)` | explicit `AgentResource` classes for `show-table` and page context (default: every panel resource implementing the contract) |
| `authorizeUsing(fn (string $ability): bool)` | how a tool's ability is checked for the current person (default: the `Gate` when it has that ability, otherwise allowed) |
| `roleLabelUsing(fn (): ?string)` | the person's role label for the prompt and refusals |
| `credentialsUsing(fn (): ?WorkspaceCredentials)` | where a workspace's own provider, key and model come from |
| `chat(bool)` | the Chat and Chats pages, the topbar button and the sidebar block |
| `agentAccess(bool $enabled, ?string $ability, Closure\|string\|null $group)` | the token page, its gate and navigation group |
| `limits(bool $enabled, ?Closure $authorize)` | the operator's AI limits resource and who may edit it (default: any signed-in user of the panel) |
| `hideAskButtonOn(array $routePatterns)` | route name patterns without the topbar button (the chat itself is always excluded) |

Two panels may register the plugin: the tenant panel with the chat and the token page, the operator panel with `chat(false)->agentAccess(false)->limits()`.

## The Agents facade

`Packstub\Agents\Facades\Agents` reads back what the app told the package: `name()`, `panel()`, `tenant()`, `toolClasses()`, `resourceClasses()`, `allows($ability)`, `roleLabel()`, `credentials()`, `canManageLimits()`. Tools and views use it; your own code may too.

## Translations and views

Strings are `__()` calls keyed by the English text, with JSON files for German, Spanish, Romanian and Russian in `resources/lang`. Add your own language by publishing a JSON file with the same keys into your app's `lang/` directory. Views are published with `vendor:publish --tag=packstub-agents-views` and live under the `packstub-agents::` namespace.
