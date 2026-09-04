# Filament Agents

![Filament Agents](https://raw.githubusercontent.com/packstub/filament-agents/main/art/banner.jpg)

An in-panel AI assistant and an MCP server for Filament v5 panels, built on laravel/ai and laravel/mcp. One tool list serves both: the chat inside the panel and Claude Code, Claude Desktop, Cursor or any other MCP client, with the panel's own authorization deciding who may run what. Free and open source (MIT).

- Repository: [github.com/packstub/filament-agents](https://github.com/packstub/filament-agents)
- Packagist: [packstub/filament-agents](https://packagist.org/packages/packstub/filament-agents)
- Support: [GitHub issues](https://github.com/packstub/filament-agents/issues)

## What you get

| Feature | What it means for you |
| --- | --- |
| **One tool list, two front doors** | Every capability is a `laravel/mcp` tool class with an ability. The in-panel chat calls it through laravel/ai; external agents call it over HTTP with a token minted in the panel. Add a tool to the list once and it is everywhere. |
| **The panel's authorization** | A tool declares the same ability string that gates the resource or action it mirrors. The assistant can never do more than the signed-in person could by hand. A token narrows that further for external agents: read-only, or just the tools they need. |
| **Approve-in-chat writes** | Read-only tools run directly. Any other tool is a proposal: the person sees what would run and approves or rejects it in the chat. Over MCP, a write token runs it directly with the person's role. |
| **Live tables and charts** | `show-table` renders the resource's own Filament table under the answer, with its search, filters, sorting and row actions. `draw-chart` and any tool result with a `chart` key render a chart. The "Ask …" button carries the record being viewed into the chat as page context. |
| **A bounded bill** | A per-user burst limit, answers per day and tokens per month per workspace, tokens per day and per month per user, and a prompt length cap, checked before a turn reaches the provider. An operator page overrides them per workspace and per user. |
| **Your assistant, your prompt** | A scaffolded agent class with two slots (who it is, what the workspace is) on top of generic working and answering rules; the static block is cached by the provider, the dynamic block carries date, person, role, language and page context. Anthropic or OpenAI, with a model picker (Auto, Fast, Deep). |
| **Tenancy-aware** | The MCP path can carry the workspace, tokens are bound to it, conversations can live in the tenant database and a workspace can bring its own provider key. Works without tenancy too. |
| **Translatable** | Every string goes through `__()`; German, Spanish, Romanian and Russian are included. |

## Guides

| Guide | What it covers |
| --- | --- |
| [Installation](installation.md) | Requirements, the install command, the theme `@source`, registering the plugin, the provider key |
| [Tools](tools.md) | Writing an `AgentTool`, abilities, read-only versus write tools, the server class, errors, the scaffold command |
| [The assistant](assistant.md) | The chat page, approvals, feedback, the model picker, and the `Agent` class with its persona, domain, rules and context |
| [Tables and charts](tables-and-charts.md) | `AgentResource`, `InteractsWithAgent`, the `Filter` vocabulary, `show-table`, `draw-chart`, page context |
| [MCP clients](mcp-clients.md) | The Agent access page, tokens, abilities, tool scopes and expiry, connecting Claude Code, Claude Desktop and Cursor, the endpoint's middleware |
| [Budgets and limits](budgets-and-limits.md) | The platform ceiling in config, the AI limits resource, inheritance, `AgentBudget` |
| [Tenancy](tenancy.md) | The `{tenant}` path, workspace-bound tokens, per-workspace keys and limits, database-per-tenant migrations |
| [Configuration](configuration.md) | Every config key and environment variable, the fluent `AgentsPlugin` API |
| [Security](security.md) | The threat model: trust boundaries, prompt injection, what the package enforces and what stays yours |
| [Testing](testing.md) | Faking the model, driving tools, testing the MCP endpoint in your app |

## At a glance

```bash
composer require packstub/filament-agents
php artisan packstub-agents:install
php artisan filament:assets
php artisan packstub-agents:tool SearchOrders --ability=orders.view
```

```php
use Packstub\Agents\AgentsPlugin;

->plugin(
    AgentsPlugin::make()
        ->name('Ask Acme')
        ->agent(\App\Ai\Agents\Assistant::class)
        ->server(\App\Mcp\Servers\AcmeServer::class)
        ->authorizeUsing(fn (string $ability) => auth()->user()->can($ability)),
)
```

Put `ANTHROPIC_API_KEY` or `OPENAI_API_KEY` in `.env` (with `AGENT_PROVIDER`), open the panel, and press **Ask Acme**.
