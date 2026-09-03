# packstub/filament-agents

An in-panel AI assistant (laravel/ai) and an MCP server (laravel/mcp) for Filament v5 panels, sharing one tool list. Extracted from Orderflux and Taxflux (`../../../orderflux/app`, `../../../taxflux/app`), which consume it through a path repository and are its dogfood.

## Commands

```bash
composer test               # Pest suite (Testbench, in-memory SQLite, a fixture panel with a Widget resource)
composer test:filter <name>
composer lint               # Pint
```

## Layout

- `src/Ai` — the base `Agent` (persona/domain slots, generic rules, provider options), `ApprovableTool`, `WorkspaceCredentials`.
- `src/Mcp` — `AgentTool` (ability check, read/write token gate, error mapping), `AgentServer`, generic tools `ShowTable` and `DrawChart`.
- `src/Filters`, `src/Contracts/AgentResource`, `src/Concerns/InteractsWithAgent`, `src/Support/AgentResources`, `src/Support/PageContext` — a resource's filter vocabulary and summaries, discovered from the panel.
- `src/Support/{AgentBudget,AgentLimits,AgentModels}` — spending guard rails and provider/model resolution.
- `src/Filament` — `Chat`, `Chats`, `AgentAccess` pages and the operator `AgentLimitResource`; `src/Livewire/AgentTable` embeds a resource table in an answer.
- `AgentsPlugin` (panel wiring, fluent config mirrored into `packstub-agents.*`), `AgentsManager` + `Facades\Agents` (what the app told us).
- `resources/views` (`packstub-agents::`), `resources/css/agents.css` (plain CSS, registered as a Filament asset), `resources/lang/*.json` (JSON translations keyed by the English text).

## Conventions

- PHP 8.4+, Pint, Pest; every change needs a test.
- UI strings are `__()` keyed by the English text; keep `resources/lang/{ro,ru,de}.json` in sync.
- Anything domain-specific (record shapes, filter vocabulary, the prompt's domain block) belongs in the consuming app, behind the `AgentResource` hooks and the agent's slots — never in this package.
- Run the Orderflux and Taxflux agent suites after a change here (`vendor/bin/pest tests/Feature/AgentTest.php` in each app).
