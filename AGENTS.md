# packstub/filament-agents

Filament v5 plugin: an in-panel AI assistant (laravel/ai) and an MCP server (laravel/mcp) sharing one tool list. **Filament Agents** on packstub.dev, **Packstub Agents** on filamentphp.com. The engine is `packstub/agents` (sibling repo `plugins/agents`, **Agents for Laravel** on packstub.dev): this plugin requires it and holds only what a panel adds. Both map the `Packstub\Agents\` namespace, so a class name may exist in one repo only.

## Commands

```bash
composer test               # Pest suite (Testbench, in-memory SQLite, a fixture panel with a Widget resource)
composer test:filter <name>
composer lint               # Pint
```

`packstub/agents` comes from Packagist. To run this suite against the sibling checkout, replace the installed copy with a symlink after `composer install`: `rm -rf vendor/packstub/agents && ln -s ../../../agents vendor/packstub/agents` (the autoloader maps `Packstub\Agents\` to `vendor/packstub/agents/src`, so no regeneration is needed). Run the core's own suite too after a change there.

## Layout

In the engine (`packstub/agents`): the base `Agent`, `ApprovableTool`, the middleware, `RunAgentTurn`, `AgentTurns`, `TurnController`, `AgentRuntime`, `AgentConversationStore`, `AgentTool`, `AgentServer`, `DrawChart`, `Filters`, `AgentResource`, `InteractsWithAgent`, `AgentResources`, `PageContext`, `AgentBudget`/`AgentLimits`/`AgentModels`, the models, `AgentsManager` + `Facades\Agents`, `AgentsServiceProvider` (config, migrations, the MCP and poll routes, the commands), `config/`, `database/migrations`, `stubs/`, the engine's strings.

Here:

- `AgentsPlugin` — panel wiring: binds `Filament\FilamentContext` as the `AgentContext`, mirrors the fluent config into `packstub-agents.*`, hands the manager the agent, server, tools, resources, middleware and callbacks, registers the pages, the resource and the panel's poll route, adds `show-table` when the panel has agent resources.
- `src/Filament/FilamentAgentsServiceProvider` — views (`packstub-agents::`), the Blade components, the UI strings, the CSS and Alpine assets, the `AgentTable` Livewire component, and the panel warm-up (`Filament::getPanels()` in a booted callback queued at register time, so it runs before the engine registers its routes).
- `src/Filament` — `FilamentContext` (the panel, its guard, its tenant, `TenantSet` keeps firing), `Chat`, `Chats`, `AgentAccess`, `TurnLog` pages, the operator `AgentLimitResource`, `Forms/ToolPicker`; `src/Livewire/AgentTable` embeds a resource table in an answer; `src/Mcp/Tools/ShowTable` is the tool that asks for one.
- `resources/views`, `resources/js/agent-chat.js` (the chat page's Alpine component: composer, polling, Stop), `resources/css/agents.css` (plain CSS, registered as a Filament asset), `resources/lang/*.json` (the UI strings, keyed by the English text; the engine ships its own).
- `docs/` customer docs (synced on every push to `main`); the panel-facing guides, pointing to the engine's docs for what runs without Filament.

## Conventions

- PHP 8.4+ (not 8.3). Every change needs a test and a `CHANGELOG.md` line.
- Changelog headings are `## <version> — <date>`; the tag is `v<version>` on `main`.
- UI strings are `__()` keyed by the English text; keep `resources/lang/{de,es,ro,ru}.json` in sync. A string the engine emits belongs in the engine's lang files.
- Anything that runs without a panel (a tool, a budget rule, a turn change, a migration, a config key) is an engine change: make it in `plugins/agents`, then bump the requirement here if the plugin needs it.
- Anything domain-specific (record shapes, filter vocabulary, the prompt's domain block) belongs in the consuming app, behind the `AgentResource` hooks and the agent's slots — never in this package.
- Apps that consume the package through a path repository should run their own agent suites after a change here.
