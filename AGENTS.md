# packstub/filament-agents

Free (MIT) Filament v5 plugin: an in-panel AI assistant (laravel/ai) and an MCP server (laravel/mcp) sharing one tool list. Published on Packagist; listed on packstub.dev as **Filament Agents** and on filamentphp.com as **Packstub Agents**. The packstub.dev store dogfoods it in its admin panel.

## Commands

```bash
composer test               # Pest suite (Testbench, in-memory SQLite, a fixture panel with a Widget resource)
composer test:filter <name>
composer lint               # Pint
```

## Layout

- `src/Ai` — the base `Agent` (persona/domain slots, generic rules, provider options), `ApprovableTool`, `WorkspaceCredentials`.
- `src/Mcp` — `AgentTool` (ability check, token gate — read/write, `tool:{name}` scope — error mapping), `AgentServer`, generic tools `ShowTable` and `DrawChart`.
- `src/Filters`, `src/Contracts/AgentResource`, `src/Concerns/InteractsWithAgent`, `src/Support/AgentResources`, `src/Support/PageContext` — a resource's filter vocabulary and summaries, discovered from the panel.
- `src/Support/{AgentBudget,AgentLimits,AgentModels}` — spending guard rails and provider/model resolution.
- `src/Filament` — `Chat`, `Chats`, `AgentAccess` pages and the operator `AgentLimitResource`; `src/Livewire/AgentTable` embeds a resource table in an answer.
- `AgentsPlugin` (panel wiring, fluent config mirrored into `packstub-agents.*`), `AgentsManager` + `Facades\Agents` (what the app told us).
- `resources/views` (`packstub-agents::`), `resources/css/agents.css` (plain CSS, registered as a Filament asset), `resources/lang/*.json` (JSON translations keyed by the English text).
- `docs/` customer docs, synced to packstub.dev by `.github/workflows/docs-sync.yml` on every push to `main`.

## Conventions

- PHP 8.4+, Pint, Pest; every change needs a test. Keep `CHANGELOG.md` current.
- Release = a `## <version> — <date>` heading in `CHANGELOG.md`, then a `v<version>` tag on `main`. Packagist picks up the tag, docs sync on the push to `main`, and `.github/workflows/release.yml` creates the GitHub release from that changelog section — no manual release step.
- UI strings are `__()` keyed by the English text; keep `resources/lang/{de,es,ro,ru}.json` in sync.
- Anything domain-specific (record shapes, filter vocabulary, the prompt's domain block) belongs in the consuming app, behind the `AgentResource` hooks and the agent's slots — never in this package.
- Apps that consume the package through a path repository should run their own agent suites after a change here.
- Listing assets/copy: use the `filament-plugin-listing` skill from the workspace root.
