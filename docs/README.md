# Filament Agents

![Filament Agents](https://raw.githubusercontent.com/packstub/art/main/filament-agents/banner.jpg)

An in-panel AI assistant and an MCP server for Filament v5 panels, built on laravel/ai and laravel/mcp. One tool list serves both: the chat inside the panel and Claude Code, Claude Desktop, Cursor or any other MCP client, with the panel's own authorization deciding who may run what. Free and open source (MIT).

- Engine: [packstub/agents](https://packstub.dev/docs/agents) — the same tools, MCP server, turns and budgets in a plain Laravel app; this plugin adds the panel pages on top
- Repository: [github.com/packstub/filament-agents](https://github.com/packstub/filament-agents)
- Packagist: [packstub/filament-agents](https://packagist.org/packages/packstub/filament-agents)
- Support: [GitHub issues](https://github.com/packstub/filament-agents/issues)
- Demo: [the 90-second video](https://youtu.be/d_lLxKsFfVU) — install in one line, a question answered with the live orders table, a chart, a change approved in one click, and the same tools in Claude Code over MCP

## Features

- **[One tool list, two front doors](tools.md)**: the panel's chat and any MCP client (Claude Code, Cursor) call the same tools.
- **[The panel's authorization](security.md#what-the-package-enforces)**: the assistant never does more than the signed-in person could, and a token narrows it further.
- **[Approve-in-chat writes](assistant.md#approvals)**: a change waits as a question with Approve and Reject, showing what it would change.
- **[A chat that feels current](assistant.md#the-chat)**: streamed answers, attachments, `@` mentions, a slide-over on any page, chats you can pin and search.
- **[Live tables and charts](tables-and-charts.md)**: the resource's own Filament table under the answer, and charts from the numbers.
- **[Beyond the records, with guard rails](assistant.md#web-search-and-the-knowledge-base)**: a cited knowledge base, web search within an allow-list, a prompt guard, redaction and a topic for every chat.
- **[A bounded bill](budgets-and-limits.md)**: answer and token limits per user and per workspace, edited on an operator page.
- **[Your assistant, your prompt](assistant.md#the-agent-class)**: a scaffolded agent class on Anthropic, OpenAI, Gemini or xAI, with failover.
- **[Tenancy-aware](tenancy.md)**: workspace-bound tokens, tenant databases and per-workspace keys, or no tenancy at all.

## Guides

| Guide | What it covers |
| --- | --- |
| [Installation](installation.md) | Requirements, the install command, the queue worker (or the sync driver), the theme `@source`, registering the plugin, the provider key |
| [Tools](tools.md) | Writing an `AgentTool`, abilities, read-only versus write tools, the server class, errors, the scaffold command |
| [The assistant](assistant.md) | The chat page, how a turn runs, Stop, Retry, regenerate and edit, long chats, approvals, feedback, the model picker, web search and the knowledge base, classification, the guard rails, and the `Agent` class with its persona, domain, rules, context and middleware |
| [Tables and charts](tables-and-charts.md) | `AgentResource`, `InteractsWithAgent`, the `Filter` vocabulary, `show-table` and the full-table page, `draw-chart`, page context |
| [MCP clients](mcp-clients.md) | The Agent access page, tokens, abilities, tool scopes and expiry, connecting Claude Code, Claude Desktop and Cursor, the endpoint's middleware |
| [Budgets and limits](budgets-and-limits.md) | The platform ceiling in config, the AI limits resource, inheritance, `AgentBudget`, what each turn cost: the AI turns page and the log line |
| [Tenancy](tenancy.md) | The `{tenant}` path, workspace-bound tokens, per-workspace keys and limits, database-per-tenant migrations |
| [Configuration](configuration.md) | Every config key and environment variable, the fluent `AgentsPlugin` API |
| [Security](security.md) | The threat model: trust boundaries, prompt injection, the prompt guard, redaction, what the package enforces and what stays yours |
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

Put `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY` or `XAI_API_KEY` in `.env` (with `AGENT_PROVIDER`), run `php artisan queue:work` (or set `AGENT_TURN_DRIVER=sync`), open the panel, and press **Ask Acme**.
