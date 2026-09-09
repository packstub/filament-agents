# packstub/filament-agents

Filament v5 plugin: an in-panel AI assistant (laravel/ai) and an MCP server (laravel/mcp) sharing one tool list. **Filament Agents** on packstub.dev, **Packstub Agents** on filamentphp.com.

## Commands

```bash
composer test               # Pest suite (Testbench, in-memory SQLite, a fixture panel with a Widget resource)
composer test:filter <name>
composer lint               # Pint
```

## Layout

- `src/Ai` — the base `Agent` (persona/domain slots, generic rules, provider options, `HasMiddleware`), `ApprovableTool`, `WorkspaceCredentials`; `Ai/Middleware/EnforceBudget` and the app's own middleware run on every turn, `Exceptions/TurnRefused` stops one with a message.
- `src/Jobs/RunAgentTurn` produces an answer and writes progress to `agent_turns`; `src/Support/AgentTurns` queues, polls, stops and records turns; `src/Http/Controllers/TurnController` is the page's poll endpoint; `src/Support/AgentRuntime` restores panel, tenant, user and locale in the worker.
- `src/Support/AgentConversationStore` — history: token-budgeted window, pruned tool results, rolling summary (`Models/ConversationSummary`), continue-in-new-chat.
- `src/Mcp` — `AgentTool` (ability check, token gate — read/write, `tool:{name}` scope — error mapping), `AgentServer`, generic tools `ShowTable` and `DrawChart`.
- `src/Filters`, `src/Contracts/AgentResource`, `src/Concerns/InteractsWithAgent`, `src/Support/AgentResources`, `src/Support/PageContext` — a resource's filter vocabulary and summaries, discovered from the panel.
- `src/Support/{AgentBudget,AgentLimits,AgentModels}` — spending guard rails and provider/model resolution.
- `src/Filament` — `Chat`, `Chats`, `AgentAccess`, `TurnLog` pages and the operator `AgentLimitResource`; `src/Livewire/AgentTable` embeds a resource table in an answer.
- `AgentsPlugin` (panel wiring, fluent config mirrored into `packstub-agents.*`), `AgentsManager` + `Facades\Agents` (what the app told us).
- `resources/views` (`packstub-agents::`), `resources/js/agent-chat.js` (the chat page's Alpine component: composer, polling, Stop), `resources/css/agents.css` (plain CSS, registered as a Filament asset), `resources/lang/*.json` (JSON translations keyed by the English text).
- `docs/` customer docs (synced on every push to `main`).

## Conventions

- PHP 8.4+ (not 8.3). Every change needs a test and a `CHANGELOG.md` line.
- Changelog headings are `## <version> — <date>`; the tag is `v<version>` on `main`.
- UI strings are `__()` keyed by the English text; keep `resources/lang/{de,es,ro,ru}.json` in sync.
- Anything domain-specific (record shapes, filter vocabulary, the prompt's domain block) belongs in the consuming app, behind the `AgentResource` hooks and the agent's slots — never in this package.
- Apps that consume the package through a path repository should run their own agent suites after a change here.
