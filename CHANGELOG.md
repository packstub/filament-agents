# Changelog

All notable changes to `packstub/filament-agents` are documented here.

## Unreleased

### Added

- **Scoped agent access tokens.** The Create token modal lists the tools the person's role allows, reads and writes apart, so a token can be limited to the few an agent needs (`tool:{name}` abilities). A scoped token sees only those tools in `tools/list` and a direct call to any other is refused; a read token no longer lists write tools at all. Tokens can also expire (7, 30, 90 or 365 days, Sanctum's `expires_at`). The table shows each token's tools and expiry. Existing tokens keep working unchanged: no `tool:` ability means every tool the role allows.
- `AgentTool::tokenRefusal()`, `accessToken()`, `tokenTools()` and `tokenIsScoped()` for apps that gate their own tools or show what a token may do.

## 1.0.1 — 2026-09-04

### Fixed

- Rejecting a proposed change now hands the model a reason instead of a bare "no", so the turn continues and the model can acknowledge and offer the next step; the decided proposal stays a card (Done / Rejected) instead of collapsing into a tool chip once the paused list is empty.
- The prompt's generic rules name the tools as `laravel/mcp` registers them (`show-table`, `draw-chart`); docs and the fixtures follow.
- An operator panel that switches the Agent access page off no longer resets the page's ability and group for the tenant panel.
- The "Read" / "Write" ability badges are translated; the AI limits page keeps one casing ("AI limits").

## 1.0.0 — 2026-09-03

First public release, under the MIT license.

### Added

- **One tool list for the chat and the MCP server**: `AgentTool` (a `laravel/mcp` tool with an `$ability`, a `run()` returning data for the model and domain errors mapped to tool errors), `AgentServer` (name, instructions, `$tools`), the `packstub-agents:tool` and `packstub-agents:agent` scaffolds and the `packstub-agents:install` command.
- **In-panel chat**: a `Chat` page streaming answers over Livewire, an "Ask …" topbar button that carries the record being viewed as page context, recent conversations in the sidebar and a `Chats` page, thumbs up / down feedback on answers, a model picker (Auto / Fast / Deep) remembered per session.
- **Approve-in-chat writes**: read-only tools run directly; every other tool is wrapped as an `ApprovableTool` so the person sees the proposed call and approves or rejects it before it runs.
- **Live tables and charts in answers**: `show_table` renders a resource's own Filament table under the answer (`AgentResource` contract, `InteractsWithAgent` defaults, a `Filter` vocabulary shared with the app's search tools through `AgentResources`); `draw_chart` and any tool result with a `chart` key render a chart.
- **MCP over HTTP**: `POST /mcp` behind `throttle`, `auth:sanctum` and `AuthenticateAgent`; an **Agent access** page mints Sanctum tokens with `read` / `write` abilities, shown once, listed and revocable; a read token cannot run write tools.
- **Budgets and the operator page**: per-user burst limit, answers per day and tokens per month per workspace, tokens per day and per month per user and a prompt length cap, checked before a turn reaches the provider (`AgentBudget`); config defaults overridden by global, per-workspace and per-user rows edited in the **AI limits** resource (`AgentLimits`, `AgentLimit`).
- **Tenancy**: an `mcp/{tenant}` path resolves the workspace by the panel's tenant slug, checks membership and the token's `tenant:{slug}` ability and fires Filament's `TenantSet`; `credentialsUsing()` lets a workspace bring its own provider, key and model; `limits_connection` and `run_migrations` for database-per-tenant apps.
- Prompt assembly with a provider-cached static block (persona, domain, working and answering rules) and a dynamic block (date, workspace, person, role, language, page context); reasoning effort per model for Anthropic and OpenAI.
- German, Spanish, Romanian and Russian translations.

### Changed

- The 0.x line (governance-only MCP tools with capability grants, pending approvals and an audit trail) was replaced by this rebuild. Its consumers migrate to `AgentTool`, the panel's own authorization and Sanctum tokens; see the docs.
