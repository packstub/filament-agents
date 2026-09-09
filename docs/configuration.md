# Configuration

`php artisan packstub-agents:install` publishes `config/packstub-agents.php`. Most values can also be set fluently on `AgentsPlugin` in the panel provider; the plugin mirrors those into the config so every runtime (queue, console, MCP requests) sees one truth.

## config/packstub-agents.php

| Key | Default | Env | What it does |
| --- | --- | --- | --- |
| `name` | `Assistant` | `AGENT_NAME` | how the assistant introduces itself; `AgentsPlugin::name()` overrides it |
| `panel` | `null` | | the panel the assistant lives in; set by the plugin when it registers |
| `provider` | `anthropic` | `AGENT_PROVIDER` | `anthropic`, `openai`, `gemini` or `xai` have picker entries; any other laravel/ai text provider (`ollama`, `openrouter`, `mistral`, `groq`, `deepseek`…) runs on its smartest and cheapest models. The platform default; a workspace may bring its own |
| `failover` | `[]` | `AGENT_FAILOVER` | providers to fall back to, in order (`gemini,openai`), when the platform provider refuses a turn before it started answering; see [Failover](assistant.md#failover) |
| `enabled` | `null` | `AGENT_ENABLED` | `null` = enabled when a key exists for the provider; `false` hides the chat |
| `models` | see below | `AGENT_MODEL`, `AGENT_MODEL_FAST`, `AGENT_MODEL_DEEP` | the picker entries per provider: label, model, effort |
| `max_steps` | `12` | | tool round-trips one turn may take before the agent has to answer |
| `max_tokens` | `4096` | | answer length |
| `max_conversation_messages` | `40` | | how many earlier messages a long chat replays |
| `middleware` | `[]` | | your own agent middleware, run on every turn after the package's guard rails; see [Middleware](assistant.md#middleware) |
| `history.max_tokens` | `24000` | `AGENT_HISTORY_MAX_TOKENS` | the history window, in estimated tokens; what no longer fits is folded into a rolling summary the model reads first |
| `history.keep_tool_results_turns` | `3` | | tool results older than this many turns are replaced by a one-line placeholder when replayed |
| `history.notice_share` | `0.7` | | from this share of the window the chat suggests continuing in a new chat |
| `history.meter_share` | `0.25` | | from this share of the window the composer shows the context ring; below it the composer is clean |
| `history.compress_keep_turns` | `2` | | how many of the latest exchanges **Compress now** keeps verbatim while it folds the rest into the rolling summary |
| `chat.driver` | `queue` | `AGENT_TURN_DRIVER` | how a turn runs: `queue` hands the job to a worker, `sync` runs it inside the request (no worker; an answer ends with the tab that asked). Also `AgentsPlugin::make()->chat(driver: 'sync')` |
| `chat.queue_connection` | `null` | `AGENT_QUEUE_CONNECTION` | the queue connection the turn job runs on with the `queue` driver; `null` = the app's default |
| `chat.queue` | `null` | `AGENT_QUEUE` | the queue name; `null` = the connection's default |
| `chat.job_timeout` | `600` | `AGENT_JOB_TIMEOUT` | how long one turn may run on the worker, in seconds; a turn whose job went quiet for longer is shown as failed, with a Retry |
| `chat.poll_interval` | `600` | `AGENT_POLL_INTERVAL` | how often the page asks for the answer so far while a turn runs, in milliseconds |
| `chat.keep_turns_days` | `90` | `AGENT_KEEP_TURNS_DAYS` | how long ended turns (the per-turn record) are kept for the AI turns page; `null` keeps them; pruned by `model:prune --model=Packstub\Agents\Models\AgentTurn` |
| `log.channel` | `null` | `AGENT_LOG_CHANNEL` | the log channel that gets one line per ended turn (provider, model, tokens, tools, duration, how it ended); `null` logs nothing. See [What each turn cost](budgets-and-limits.md#what-each-turn-cost) |
| `limits.*` | see [Budgets and limits](budgets-and-limits.md) | `AGENT_TURNS_PER_MINUTE` … | the platform ceiling |
| `limits_connection` | `null` | `AGENT_LIMITS_CONNECTION` | the connection of the `agent_limits` table (the central one in a database-per-tenant app) |
| `mcp.enabled` | `true` | `AGENT_MCP_ENABLED` | the MCP endpoint and the Agent access page |
| `mcp.path` | `mcp` | | the endpoint path; `mcp/{tenant}` in a panel with tenancy |
| `mcp.server` | `null` | | an `AgentServer` subclass; `null` = the package's server with the tools registered on the plugin |
| `mcp.middleware` | `['throttle:60,1', 'auth:sanctum', AuthenticateAgent::class]` | | the endpoint's middleware |
| `run_migrations` | `true` | | run the package migrations from the vendor directory; `false` to publish and split them |

The provider keys themselves live in laravel/ai's `config/ai.php` (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY`, `XAI_API_KEY`, and so on for the other providers).

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
    'gemini' => [
        'auto' => ['label' => 'Auto', 'model' => env('AGENT_MODEL', 'gemini-3.8-flash'), 'effort' => 'medium'],
        'fast' => ['label' => 'Fast', 'model' => env('AGENT_MODEL_FAST', 'gemini-3.5-flash-lite'), 'effort' => 'low'],
        'deep' => ['label' => 'Deep', 'model' => env('AGENT_MODEL_DEEP', 'gemini-3.8-flash'), 'effort' => 'high'],
    ],
    'xai' => [
        'auto' => ['label' => 'Auto', 'model' => env('AGENT_MODEL', 'grok-4.6'), 'effort' => 'medium'],
        'fast' => ['label' => 'Fast', 'model' => env('AGENT_MODEL_FAST', 'grok-4.6'), 'effort' => 'low'],
        'deep' => ['label' => 'Deep', 'model' => env('AGENT_MODEL_DEEP', 'grok-4.6'), 'effort' => 'xhigh'],
    ],
],
```

Rename, remove or add entries; the picker shows whatever is there. A `null` model resolves to the provider's smartest model (or cheapest for the `fast` key). A provider with no entries at all (Ollama, OpenRouter, Mistral, Groq, DeepSeek…) gets Auto (smartest) and Fast (cheapest) with no effort; add an entry to pin models or to offer Deep. Effort is passed as Anthropic's `output_config.effort`, OpenAI's and xAI's `reasoning.effort` (reasoning models only) or Gemini's thinking level (`low`, `medium`, `high`; `xhigh` is sent as `high`). When you pin a model that rejects the parameter, set its effort to `null`.

## AgentsPlugin

```php
use Packstub\Agents\AgentsPlugin;

AgentsPlugin::make()
    ->name('Ask Acme')
    ->agent(Assistant::class)
    ->server(AcmeServer::class)
    ->tools([SearchOrders::class, ShowTable::class])
    ->resources([OrderResource::class, CustomerResource::class])
    ->middleware([AuditTurns::class])
    ->authorizeUsing(fn (string $ability): bool => auth()->user()->can($ability))
    ->roleLabelUsing(fn (): ?string => auth()->user()->role?->getLabel())
    ->credentialsUsing(fn (): ?WorkspaceCredentials => ...)
    ->chat(true, driver: 'queue')
    ->agentAccess(enabled: true, ability: 'setup.view', group: 'Setup')
    ->limits(enabled: true, authorize: fn (): bool => auth()->user()->is_admin)
    ->turnLog()
    ->hideAskButtonOn(['*.pages.dashboard']);
```

| Method | |
| --- | --- |
| `name(string)` | how the assistant is called in the panel |
| `agent(class)` | your `Agent` subclass (default: the package's `DefaultAgent`) |
| `server(class)` | the `AgentServer` subclass with the tool list, name and instructions |
| `tools(array)` | the tool list when there is no server class |
| `resources(array)` | explicit `AgentResource` classes for `show-table` and page context (default: every panel resource implementing the contract) |
| `middleware(array)` | your own agent middleware — classes with `handle(AgentPrompt $prompt, Closure $next)`, instances or closures — run on every turn after the package's guard rails, after the ones in config; see [Middleware](assistant.md#middleware) |
| `authorizeUsing(fn (string $ability): bool)` | how a tool's ability is checked for the current person (default: the `Gate` when it has that ability, otherwise allowed) |
| `roleLabelUsing(fn (): ?string)` | the person's role label for the prompt and refusals |
| `credentialsUsing(fn (): ?WorkspaceCredentials)` | where a workspace's own provider, key and model come from |
| `history(?int $maxTokens, ?int $keepToolResultsTurns, ?float $noticeShare, ?float $meterShare, ?int $compressKeepTurns)` | what a long chat replays and when the context ring shows; each argument given is mirrored into the `history.*` key of the same name |
| `chat(bool $enabled, ?string $driver)` | the Chat and Chats pages, the topbar button and the sidebar block; `driver` is `queue` (a worker) or `sync` (inside the request), mirrored into `chat.driver` |
| `agentAccess(bool $enabled, ?string $ability, Closure\|string\|null $group)` | the token page, its gate and navigation group |
| `limits(bool $enabled, ?Closure $authorize)` | the operator's AI limits resource and who may edit it (default: any signed-in user of the panel) |
| `turnLog(bool $enabled)` | the operator's AI turns page (one row per turn: who, model, tokens, tools, duration, how it ended), gated like the limits; default: shown wherever `limits()` is |
| `hideAskButtonOn(array $routePatterns)` | route name patterns without the topbar button (the chat itself is always excluded) |

Two panels may register the plugin: the tenant panel with the chat and the token page, the operator panel with `chat(false)->agentAccess(false)->limits()`.

## The Agents facade

`Packstub\Agents\Facades\Agents` reads back what the app told the package: `name()`, `panel()`, `tenant()`, `toolClasses()`, `resourceClasses()`, `middleware()`, `allows($ability)`, `roleLabel()`, `credentials()`, `canManageLimits()`. Tools and views use it; your own code may too.

## Translations and views

Strings are `__()` calls keyed by the English text, with JSON files for German, Spanish, Romanian and Russian in `resources/lang`. Add your own language by publishing a JSON file with the same keys into your app's `lang/` directory. Views are published with `vendor:publish --tag=packstub-agents-views` and live under the `packstub-agents::` namespace.
