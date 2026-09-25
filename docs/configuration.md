# Configuration

`php artisan packstub-agents:install` publishes `config/packstub-agents.php`. Most values can also be set fluently on `AgentsPlugin` in the panel provider; the plugin mirrors those into the config so every runtime (queue, console, MCP requests) sees one truth.

## config/packstub-agents.php

| Key | Default | Env | What it does |
| --- | --- | --- | --- |
| `name` | `Assistant` | `AGENT_NAME` | how the assistant introduces itself; `AgentsPlugin::name()` overrides it |
| `panel` | `null` | | the panel the assistant lives in; set by the plugin when it registers |
| `provider` | `anthropic` | `AGENT_PROVIDER` | `anthropic`, `openai`, `gemini` or `xai` have picker entries; any other laravel/ai text provider (`ollama`, `openrouter`, `mistral`, `groq`, `deepseek`…) runs on its smartest and cheapest models. The platform default; a workspace may bring its own |
| `failover` | `[]` | `AGENT_FAILOVER` | providers to fall back to, in order (`gemini,openai`), when the platform provider refuses a turn before it started answering; see [Failover](assistant.md#failover) |
| `enabled` | `null` | `AGENT_ENABLED` | `null` = enabled when a key exists for the provider in use or for the provider of any picker entry; `false` hides the chat |
| `models` | see below | `AGENT_MODEL`, `AGENT_MODEL_FAST`, `AGENT_MODEL_DEEP` | the picker entries per provider: model, effort, an optional label (the model's name otherwise) and optionally the provider the entry runs on |
| `max_steps` | `12` | | tool round-trips one turn may take before the agent has to answer |
| `max_tokens` | `4096` | | answer length |
| `max_conversation_messages` | `40` | | a second ceiling on the history window: at most this many earlier messages are replayed, whatever `history.max_tokens` allows — raise both to widen a long chat's window |
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
| `chat.worker_wait` | `10` | `AGENT_WORKER_WAIT` | how long a question may wait for a worker before the status line says none has taken it, in seconds (the queue driver only) |
| `chat.poll_interval` | `600` | `AGENT_POLL_INTERVAL` | how often the page asks for the answer so far while a turn runs, in milliseconds — the fallback when the event stream cannot be held open |
| `chat.stream_interval`, `chat.stream_seconds` | `150`, `55` | `AGENT_STREAM_INTERVAL`, `AGENT_STREAM_SECONDS` | how often the event stream checks the turn while it pushes changes, and how long one stream stays open before the browser reconnects |
| `chat.attachments.*` | on, the default disk, 10 MB, 5 files, images / PDF / text / CSV / Markdown / JSON | `AGENT_ATTACHMENTS`, `AGENT_ATTACHMENTS_DISK`, `AGENT_ATTACHMENTS_MAX_KB`, `AGENT_ATTACHMENTS_TEMPORARY_URLS` | the files a person may attach to a question; see [Attachments](https://packstub.dev/docs/agents/assistant#attachments) |
| `pricing.currency`, `pricing.models` | `USD`, `[]` | `AGENT_PRICING_CURRENCY` | prices per million tokens by model name, for the cost on the AI turns page and in the context ring; see [Cost in money](https://packstub.dev/docs/agents/budgets-and-limits#cost-in-money) |
| `email.*` | off | `AGENT_EMAIL`, `AGENT_EMAIL_SECRET`, `AGENT_EMAIL_FROM` | the assistant by email; see [The assistant by email](https://packstub.dev/docs/agents/assistant#the-assistant-by-email) |
| `chat.path` | `agents` | | without a panel, where the poll endpoint lives: `GET {path}/chat/{conversation}/turn`, see [Agents for Laravel](https://packstub.dev/docs/agents/installation#routes) |
| `chat.middleware` | `['web', 'auth']` | | the middleware of that endpoint; a panel that shows the chat registers its own on the panel's routes |
| `chat.keep_turns_days` | `90` | `AGENT_KEEP_TURNS_DAYS` | how long ended turns (the per-turn record) are kept for the AI turns page; `null` keeps them; pruned by `model:prune --model=Packstub\Agents\Models\AgentTurn` |
| `log.channel` | `null` | `AGENT_LOG_CHANNEL` | the log channel that gets one line per ended turn (provider, model, tokens, tools, duration, how it ended); `null` logs nothing. See [What each turn cost](budgets-and-limits.md#what-each-turn-cost) |
| `limits.*` | see [Budgets and limits](budgets-and-limits.md) | `AGENT_TURNS_PER_MINUTE`, `AGENT_TURNS_PER_DAY`, `AGENT_TOKENS_PER_DAY`, `AGENT_TOKENS_PER_MONTH`, `AGENT_USER_TOKENS_PER_DAY`, `AGENT_USER_TOKENS_PER_MONTH`, `AGENT_PROMPT_MAX_CHARS` | the platform ceiling |
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
        'auto' => ['label' => null, 'model' => env('AGENT_MODEL', 'claude-opus-5'), 'effort' => 'medium'],
        'fast' => ['label' => null, 'model' => env('AGENT_MODEL_FAST', 'claude-haiku-4-5'), 'effort' => null],
        'deep' => ['label' => null, 'model' => env('AGENT_MODEL_DEEP', 'claude-opus-5'), 'effort' => 'xhigh'],
        // 'flash' => ['label' => 'Gemini Flash', 'provider' => 'gemini', 'model' => 'gemini-3.5-flash-lite', 'effort' => 'low'],
        // 'local' => ['label' => 'Local', 'provider' => 'ollama', 'model' => 'llama3.3', 'effort' => null, 'failover' => []],
    ],
    'openai' => [
        'auto' => ['label' => null, 'model' => env('AGENT_MODEL'), 'effort' => 'medium'],
        'fast' => ['label' => null, 'model' => env('AGENT_MODEL_FAST'), 'effort' => 'low'],
        'deep' => ['label' => null, 'model' => env('AGENT_MODEL_DEEP'), 'effort' => 'high'],
    ],
    'gemini' => [
        'auto' => ['label' => null, 'model' => env('AGENT_MODEL', 'gemini-3.8-flash'), 'effort' => 'medium'],
        'fast' => ['label' => null, 'model' => env('AGENT_MODEL_FAST', 'gemini-3.5-flash-lite'), 'effort' => 'low'],
        'deep' => ['label' => null, 'model' => env('AGENT_MODEL_DEEP', 'gemini-3.8-flash'), 'effort' => 'high'],
    ],
    'xai' => [
        'auto' => ['label' => null, 'model' => env('AGENT_MODEL', 'grok-4.6'), 'effort' => 'medium'],
        'fast' => ['label' => null, 'model' => env('AGENT_MODEL_FAST', 'grok-4.6'), 'effort' => 'low'],
        'deep' => ['label' => null, 'model' => env('AGENT_MODEL_DEEP', 'grok-4.6'), 'effort' => 'xhigh'],
    ],
],
```

Each entry is one item in the picker. Its fields:

| Field | Meaning |
| --- | --- |
| `label` | what the picker shows; `null` names the entry after its model (Claude Opus 5). A second unlabelled entry on the same model gets its key added (Claude Opus 5 · Deep) |
| `model` | the model id, in the provider's own terms; `null` is the provider's smartest model, or its cheapest on the `fast` key |
| `effort` | Anthropic's `output_config.effort`, OpenAI's and xAI's `reasoning.effort` (reasoning models only) or Gemini's thinking level: `low`, `medium`, `high` (`xhigh` is sent as `high`). `null` sends nothing; use it for a model that rejects the parameter |
| `provider` | optional: the provider the entry runs on, when it is not the list's own. See [Mixing providers](#mixing-providers) |
| `failover` | optional: this entry's own fallback list, replacing the global `failover`; `[]` means none |

The picker shows the list of the provider in use: `models[provider]`, or the workspace's own provider when it brings a key. Rename, remove or add entries; the keys are yours.

#### A provider without entries

Ollama, OpenRouter, Mistral, Groq, DeepSeek… get two entries: `auto` on the provider's smartest model and `fast` on its cheapest, as laravel/ai names them, with no effort. On OpenRouter those are Claude Opus 5 and Claude Haiku 4.5, routed through OpenRouter. To run the models you chose the provider for, add its list, with an OpenRouter model id (`vendor/model`, from [openrouter.ai/models](https://openrouter.ai/models)) in each entry:

```php
'models' => [
    'openrouter' => [
        'auto' => ['label' => 'Mercury 2.5', 'model' => 'inception/mercury-2.5', 'effort' => null],
        'fast' => ['label' => 'GLM 5.3 Flash', 'model' => 'z-ai/glm-5.3-flash', 'effort' => null],
        'deep' => ['label' => 'DeepSeek 4.1 Flash', 'model' => 'deepseek/deepseek-v4.1-flash', 'effort' => null],
    ],
],
```

The key is `OPENROUTER_API_KEY` in `.env`, read by the `openrouter` entry of laravel/ai's `config/ai.php`; that entry also takes a `url` for a compatible gateway and the `http_referer` and `x_title` headers OpenRouter uses for app attribution. Effort stays `null` on OpenRouter, since the reasoning knob differs per model behind it.

#### Mixing providers

One list can offer models of several providers. An entry names the provider it runs on with `provider`; its `model` and `effort` are in that provider's terms. Claude as the default, Gemini Flash for cheap questions and a local Ollama model for data that must not leave the server:

```php
'provider' => 'anthropic',
'failover' => ['gemini'],

'models' => [
    'anthropic' => [
        'auto' => ['label' => null, 'model' => 'claude-opus-5', 'effort' => 'medium'],
        'fast' => ['label' => null, 'model' => 'claude-haiku-4-5', 'effort' => null],
        'flash' => ['label' => 'Gemini Flash', 'provider' => 'gemini', 'model' => 'gemini-3.5-flash-lite', 'effort' => 'low'],
        'local' => ['label' => 'Local', 'provider' => 'ollama', 'model' => 'llama3.3', 'effort' => null, 'failover' => []],
    ],
],
```

```dotenv
ANTHROPIC_API_KEY=sk-ant-…
GEMINI_API_KEY=AIza…
OLLAMA_API_KEY=local
```

- The entries live under the platform provider's list (`anthropic` here); `AGENT_PROVIDER` picks the list, `provider` on an entry picks where that entry runs.
- An entry on another provider is listed only when that provider has a key in `config/ai.php`. Without `GEMINI_API_KEY`, Gemini Flash is left out, quietly. Ollama needs no key, so give `OLLAMA_API_KEY` any value for its entry to count as configured.
- The picker groups the entries under provider headings, the platform provider first: Anthropic, then Gemini, then Ollama.
- Failover works per entry: the global `failover` list applies to every entry, with the entry's own provider skipped and the platform provider included when it is listed. Here Claude falls back to Gemini, and Gemini Flash has nothing to fall back to. An entry's own `failover` replaces the global list; `[]` on `local` keeps that model local.
- A workspace on its own key sees only the entries of its provider, see [Tenancy](tenancy.md#a-workspaces-own-key). The chat is on when the provider in use has a key, or when any listed entry's provider has one.

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
    ->slideOver()
    ->shortcut('mod+j')
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
| `chat(bool $enabled, ?string $driver)` | the Chat and Chats pages, the topbar button and the sidebar block (an "Ask …" navigation item on a panel with top navigation); `driver` is `queue` (a worker) or `sync` (inside the request), mirrored into `chat.driver` |
| `agentAccess(bool $enabled, ?string $ability, Closure\|string\|null $group)` | the token page, its gate and navigation group |
| `limits(bool $enabled, ?Closure $authorize)` | the operator's AI limits resource and who may edit it (default: any signed-in user of the panel) |
| `turnLog(bool $enabled)` | the operator's AI turns page (one row per turn: who, model, tokens, tools, duration, how it ended), gated like the limits; default: shown wherever `limits()` is |
| `hideAskButtonOn(array $routePatterns)` | route name patterns without the topbar button (the chat itself is always excluded) |
| `slideOver(bool $enabled)` | whether the "Ask …" button opens the chat as a slide-over on the right of the current page (default) or leads to the chat page |
| `shortcut(?string $keys)` | the keyboard shortcut that opens the chat from any page: `mod+j` (the default: ⌘J on a Mac, Ctrl+J elsewhere), `mod+shift+a`…; `null` for none |

Two panels may register the plugin: the tenant panel with the chat and the token page, the operator panel with `chat(false)->agentAccess(false)->limits()`. The plugin's id is `packstub-agents` (`$panel->getPlugin('packstub-agents')`).

## The Agents facade

`Packstub\Agents\Facades\Agents` reads back what the app told the package: `name()`, `tenant()`, `inPanel()`, `toolClasses()`, `resourceClasses()`, `middleware()`, `allows($ability)`, `roleLabel()`, `credentials()`, `canManageLimits()`, `registeredResources()`, `agentAccess()`, `agentAccessAbility()`, `agentAccessGroup()`, `askButtonHiddenOn($routeName)`, the tenancy hooks (`tenantModelClass()`, `tenantSlugAttribute()`, `tenantResolver()`, `tenantEnterHook()`), and `context()` — the `AgentContext` that knows who is acting and where (the panel's `FilamentContext`, whose `panel()` is the panel the assistant lives in, or the `LaravelContext` of a plain app). Tools and views use it; your own code may too. Without a panel the same facade is how the app registers itself — `useAgent()`, `useServer()`, `useTools()`, `addTools()`, `useResources()`, `useMiddleware()`, `authorizeUsing()`, `roleLabelUsing()`, `credentialsUsing()`, `limitsAuthorizeUsing()`, `tenantUsing()`, `tenantModel()`, `enteringTenant()` — see [Agents for Laravel](https://packstub.dev/docs/agents/installation).

## Translations and views

Strings are `__()` calls keyed by the English text, with JSON files for German, Spanish, Romanian and Russian in `resources/lang`. Add your own language by publishing a JSON file with the same keys into your app's `lang/` directory. Views are published with `vendor:publish --tag=packstub-agents-views` and live under the `packstub-agents::` namespace.
