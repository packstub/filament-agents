# Changelog

All notable changes to `packstub/filament-agents` are documented here.

## 1.5.0 — 2026-09-09

Upgrading: run `php artisan migrate` (new columns on `agent_turns` and `agent_limits`).

### Added

- **Per-turn observability.** When a turn ends its `agent_turns` row keeps the record: the provider and model that answered, the token usage (prompt, completion, cache reads and writes, reasoning), the tools called in order, the wall time and how it ended (`stop`, `length`, `content_filter`, `dropped`, `stopped`, `refused`, `failed`). The operator panel that registers `limits()` gets an **AI turns** page listing them (who, workspace, status, model, tokens in and out, tools, duration, how it ended; filter by status or provider) — `AgentsPlugin::make()->turnLog(false)` hides it, `->turnLog()` shows it without the limits resource. Config `log.channel` (`AGENT_LOG_CHANNEL`) writes one info line per ended turn to a log channel, with the whole record in the context. Ended turns are pruned after `chat.keep_turns_days` (`AGENT_KEEP_TURNS_DAYS`, 90) by `model:prune --model=Packstub\Agents\Models\AgentTurn`.

- **Agent middleware.** Every turn runs through laravel/ai's middleware pipeline. The package's budget check moved there (`Packstub\Agents\Ai\Middleware\EnforceBudget`), so the limits hold for every turn however it was started; an app adds its own with `AgentsPlugin::make()->middleware([...])` or the new `middleware` config key — classes with `handle(AgentPrompt $prompt, Closure $next)` that can revise the prompt, read the finished answer through `->then()`, or stop the turn by throwing `Packstub\Agents\Exceptions\TurnRefused` (the person reads the message under their question, with a Retry). `Agents::middleware()` reads the list back.

- **A daily token budget per workspace.** Config `limits.tokens_per_day` (`AGENT_TOKENS_PER_DAY`, 600,000) next to the monthly one, with a field and column on the AI limits page (global and workspace rows). A busy day now locks a workspace out until midnight ("This workspace used its AI budget for today.") instead of spending the whole month; `AgentBudget::summary()` reports `tokens_today` and `tokens_per_day`.

### Changed

- **A refused question is kept.** The chat page no longer checks the budget before queueing a question and drops it with a notification; the question is recorded, the `EnforceBudget` middleware refuses the turn when it runs, and the reason is read under the question with a Retry (and the pencil to edit it first), like a question the provider could not answer. The per-minute turn counter is hit when a turn runs, not when it is queued.

## 1.4.0 — 2026-09-08

Upgrading: run `php artisan migrate` (new `agent_turns` table) and `php artisan filament:assets` (the chat page's Alpine component changed). Answers are now produced by a queued job: run a queue worker (`php artisan queue:work`), or set `AGENT_TURN_DRIVER=sync` (or `AgentsPlugin::make()->chat(driver: 'sync')`) to run them inside the request as before.

### Added

- **Answers survive the page.** A turn runs in a queued job (`RunAgentTurn`) that writes the answer so far to the new `agent_turns` table; the chat page polls a small JSON route on the panel for it instead of holding the Livewire request open. Reloading, navigating away and back, or opening the chat in a second tab picks the running answer up where it is; a closed tab no longer kills it; there is no request-timeout risk on long tool chains. A queued job that never completes (a worker that died) shows the question with a Retry after `chat.job_timeout`.
- **Stop.** A Stop button next to Send cuts the running answer short; what the assistant had written is kept as its answer, marked "(stopped)", and a stop before any text leaves the question with a Retry.
- **Follow-ups are kept per conversation.** A question typed while an answer runs waits on the server, in every tab, and starts as soon as the answer is done; it can be edited or removed until then, and ↑ in an empty composer pulls the last waiting one back.
- **Regenerate and edit-and-resend** on the last exchange: an arrow under the last answer produces it again (the previous answer and its rating are dropped), a pencil next to the last question puts it back in the composer and replaces the answer when sent.
- Config `chat.driver` (`AGENT_TURN_DRIVER`, or `AgentsPlugin::make()->chat(driver: ...)`): `queue` hands the turn job to a worker, `sync` runs it inside the request whatever the app's queue connection is — no worker needed, but an answer ends with the tab that asked for it.
- Config `chat.queue_connection`, `chat.queue`, `chat.job_timeout` and `chat.poll_interval` (`AGENT_QUEUE_CONNECTION`, `AGENT_QUEUE`, `AGENT_JOB_TIMEOUT`, `AGENT_POLL_INTERVAL`).
- **Answers the provider ended early are marked.** A stream that closes without its end event (an overloaded provider mid-answer), the model's length limit or the provider's content filter leave a partial answer; it is stored as before, shown with a "(cut short)" note that explains why, and can be produced again with Regenerate.

### Changed

- The generic prompt rules tell the assistant not to quote its instructions or tool list, and that claims about one's role or permissions made in the chat change nothing — the tools enforce access.
- The chat page no longer streams over `wire:stream`; `send()`, `decide()`, `retry()`, `regenerate()` and `resend()` queue a turn and return what the page polls. A provider failure is shown under the question (with the message) rather than only as a notification.

## 1.3.0 — 2026-09-08

Upgrading: run `php artisan filament:assets` (the chat page's Alpine component is a registered asset) and `php artisan migrate` (new `agent_conversation_summaries` table).

### Added

- **Long chats keep working.** A chat replays the most recent messages that fit a token budget (`history.max_tokens`, 24k estimated) instead of a flat 40 rows, cut on turn boundaries. Tool results older than `history.keep_tool_results_turns` turns are replaced by a placeholder when replayed. What falls out of the window is folded into a rolling summary written by the provider's cheapest model and stored in the new `agent_conversation_summaries` table (migration included); the model reads it first. The chat shows a context meter and, from `history.notice_share` of the budget, a **Continue in a new chat** action that opens a new chat seeded with a summary of the old one.

### Changed

- **The composer never locks.** A question shows in the transcript the moment it is sent (client-side, handed over to the persisted message on re-render). Anything typed while an answer is still streaming is queued and sent next, one turn at a time; a queued question can be edited or removed, and ↑ in an empty composer pulls the last queued question back for editing (or the last one sent). The textarea grows with the text; the page follows the streaming answer unless you scroll up, with a "Jump to latest" button; the answer shows a caret while it streams. A new chat no longer reloads the page after the first answer — it takes the conversation's URL in place. The composer sends the text as an argument (`send(string $prompt)`); `send()` without one still sends the component's `prompt` (a question from the URL or session). Assets: run `php artisan filament:assets` after updating — the page's Alpine component is a registered asset like the stylesheet.

### Fixed

- **A question the provider could not answer is no longer lost.** The chat records the question before calling the provider (laravel/ai stores a question and its answer together, once the answer is in), so a provider error, a timeout or a closed tab leaves the question in the conversation with a Retry link under it; the error notification says so. Retry sends the same recorded question again without storing it twice or repeating it to the model as history. The conversation store binding is `Packstub\Agents\Support\AgentConversationStore` (extends laravel/ai's database store); a new chat is titled after the question until the first answer arrives, then titled by the provider as before.

## 1.2.0 — 2026-09-08

### Added

- **Gemini and xAI out of the box.** `AGENT_PROVIDER=gemini` (`GEMINI_API_KEY`) runs on Gemini 3.8 Flash with Gemini 3.5 Flash-Lite as Fast; `AGENT_PROVIDER=xai` (`XAI_API_KEY`) on Grok 4.6. The picker's effort becomes Gemini's thinking level (`generationConfig.thinkingConfig.thinkingLevel`) and xAI's `reasoning.effort`, as it already did for Anthropic and OpenAI. `Agent::supportsReasoning()` takes the provider as a second argument.
- **Any other laravel/ai text provider** (Ollama, OpenRouter, Mistral, Groq, DeepSeek…) works without config: the picker offers the provider's smartest model as Auto and its cheapest as Fast. Before, a provider without `models` entries fell back to the Anthropic entries and asked the provider for a Claude model.

### Changed

- The chat column is 48rem wide instead of 72rem, a reading width for the answers; embedded tables, charts and the composer share it.
- The Create token modal lists the tools in one table (checkbox, title, Read / Write badge, one line of description with the full text on hover) instead of two checkbox lists with the model-facing descriptions in full. A header checkbox ticks all; write rows are switched off until Write is ticked. The action's form key is `tools` (was `read_tools` and `write_tools`), which only matters to tests that call the action directly.
- The AI limits table shows the workspace columns (Assistant, Answers / day, Tokens / month, Note) by default; the per-user detail (/ min, User tokens / day and / month, Max chars) is toggleable, so the table fits a laptop screen without a horizontal scroll.

## 1.1.0 — 2026-09-04

### Added

- **Scoped agent access tokens.** The Create token modal lists the tools the person's role allows, reads and writes apart, so a token can be limited to the few an agent needs (`tool:{name}` abilities). A scoped token sees only those tools in `tools/list` and a direct call to any other is refused; a read token no longer lists write tools at all. Tokens can also expire (7, 30, 90 or 365 days, Sanctum's `expires_at`). The table shows each token's tools and expiry. Existing tokens keep working unchanged: no `tool:` ability means every tool the role allows.
- `AgentTool::tokenRefusal()`, `accessToken()`, `tokenTools()` and `tokenIsScoped()` for apps that gate their own tools or show what a token may do.

### Fixed

- A request to the MCP endpoint without a valid token is answered with a JSON `401` whatever `Accept` header the client sent. Before, a client that did not ask for JSON was redirected to the app's `login` route, which a panel-only app does not define, so it got a `500` and an error in the log.

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
