# Changelog

All notable changes to `packstub/filament-agents` are documented here.

## 1.17.0 — 2026-10-22

- The composer on a page of your own: `<x-packstub-agents::composer standalone method="ask" />` (#88)
- Two render hooks on the chat page, around the composer, for your own toggles and actions
- The chat page view is split into parts you can publish one at a time
- New chat in the slide-over keeps the record the chat was opened about
- Requires `packstub/agents` ^1.11, which adds a log of tool calls

**Upgrading:** run `php artisan migrate` (the `agent_tool_calls` table) and `php artisan filament:assets`.

## 1.16.0 — 2026-10-13

- The AI turns page has a **Decided by** column and filter: what read a typed reply as a decision
- A typed reply to a proposal is read more carefully: "Ok?" or "Yes, but only Alpha." no longer approves
- Postgres: chat search ignores case and unclassified chats sort as on the other databases
- Postgres: a workspace slug no longer crashes a tenant lookup
- Requires `packstub/agents` ^1.9

## 1.15.0 — 2026-10-07

- A chat the app opens for you: its first turn starts when the owner first opens it (#71)
- A message the app posts as the assistant shows like any answer, with a "(posted)" note
- The AI turns page filters on Deferred and lists posted messages
- Requires `packstub/agents` ^1.8

## 1.14.2 — 2026-10-07

A security release with more workspace and panel access fixes, the same as Agents for Laravel 1.7.2. Please update.

- A deleted or unknown workspace no longer runs a turn without a workspace (#77)
- `canAccessPanel()` is checked for MCP requests, queued turns, `AgentRun` and email, not only pages (#74)
- On a tenant panel, the AI limits and AI turns pages show only the current workspace (#75)
- The embedded table's properties are locked against tampered Livewire requests
- The "@" picker leaves out records the resource's `canView()` denies
- Requires `packstub/agents` ^1.7.2

## 1.14.1 — 2026-10-07

A security release, the same fix as Agents for Laravel 1.7.1. Reported privately by Yusuf Kef, thank you.

- Workspace membership is checked on every path into a workspace (`AgentRun`, email), not only MCP
- An MCP path without `{tenant}` on a panel with tenancy is refused with a hint to fix the config
- A shorter Features list in the README and docs
- Requires `packstub/agents` ^1.7.1

## 1.14.0 — 2026-10-02

- Approval cards preview what a write would change, from the tool's `preview()`
- Add your own filters, columns or actions to the Chats page with `->chatsTable()` (#69)
- From packstub/agents 1.7: `Agents::mapToolResultsUsing()` and a `ToolAuthorized` event for every call
- New strings in German, Spanish, Romanian and Russian

**Upgrading:** `composer update packstub/agents` (1.7 is required). Nothing changes until you use the new hooks.

## 1.13.1 — 2026-10-01

- Filament Shield's `shield:generate` no longer fails on the full-table page
- Every docs screenshot retaken in one setup, in light mode

## 1.13.0 — 2026-10-01

The chat gets its polish and its guard rails, with the engine's 1.6.

- Compact tables in answers, with "Open all 37 in a full table" for long results (#42)
- The chat header links the record it is about, and that context stays with the chat (#40)
- Retry under every unanswered question (#19)
- Opt-in on `AgentsPlugin`: prompt guard, redaction, chat classification, web search, a knowledge base
- The "Ask …" button is in the panel's primary colour (`->askButtonColor('gray')` for the old look)
- A 90-second demo video in the README and docs

**Upgrading:** requires `packstub/agents` ^1.6. Run the migrations (new `agent_conversation_classifications` table; with `run_migrations => false`, publish them again) and `php artisan filament:assets`. A test that faked a new chat's title needs a fake for `TitleAgent`, see the engine's changelog.

## 1.12.1 — 2026-09-29

- The "Ask …" button no longer shows up in a panel with the chat off (it failed on a missing route under Octane or in tests)

## 1.12.0 — 2026-09-28

The panel follows the engine to laravel/ai 1.0 and laravel/mcp 1.0.

- The model's reasoning folds above the answer, when the provider reports it
- An answer the provider gave up on is kept, marked *(interrupted)*, with Regenerate
- The status line says "Reasoning…" while the model thinks
- App middleware now wraps each model round-trip

**Upgrading:** requires `packstub/agents` ^1.5. Decide or abandon any waiting proposal, then run the migrations (with `run_migrations => false`, publish them again and put `add_steps_to_agent_conversation_messages_table` next to the tenant ones). Your own middleware moves from `handle(AgentPrompt $prompt, Closure $next)` to `handle(PendingStep $step, Closure $next)`, and a turn's usage reads `input_tokens` and `output_tokens`. Details in the engine's changelog.

## 1.11.0 — 2026-09-25

The chat catches up with what people expect from an assistant.

- Answers stream in, and the tools an answer called show as a timeline (#41)
- Code blocks with copy, attachments, `@` to mention a record, `/` for starter questions
- The "Ask …" button opens the chat in a slide-over, and `Ctrl/⌘ J` opens it from anywhere
- Rename, pin, export and search chats; earlier answer versions and Continue
- The AI turns page shows the week's numbers, a chart, the cost and the ratings
- A leaner download: development files stay in the repository

**Upgrading:** requires `packstub/agents` ^1.4; run the migrations. `->slideOver(false)` keeps the old link and `->shortcut(null)` turns the shortcut off. Regenerate and Edit now keep the earlier answer as a version.

## 1.10.0 — 2026-09-16

- A held decision keeps the chat busy, so Edit and Regenerate can no longer break a paused answer
- Deleting a chat also deletes its ratings
- The chat logic moved into the engine; nothing changes on screen
- Requires `packstub/agents` ^1.3

**Upgrading:** if you called the Chat page's static helpers (`Chat::question()` and the others), call them on `Packstub\Agents\Support\AgentChat`.

## 1.9.1 — 2026-09-15

- A typed "Yes, go ahead." over a proposal decides it, in all five languages
- Two proposals in one answer can be decided one at a time
- The table under an answer starts without search and filters (`->embeddedTable(search: true, filters: true)` brings them back)
- Tool queries run by a queue worker or over MCP now stay inside the workspace
- Docs: a 60-second demo video, the OpenRouter key and worked `models` examples
- Requires `packstub/agents` ^1.2.1

## 1.9.0 — 2026-09-10

- Starter questions on a new chat, from your agent's `suggestions()` (#22)
- "Ask …" in the top navigation for panels with `topNavigation()` (#39)
- A one-row composer with a compact model picker (#44)
- Answer controls show on hover, and always on touch screens (#44)
- Requires `packstub/agents` ^1.2

## 1.8.0 — 2026-09-10

- A proposed change reads as a question with Approve and Reject, phrased by the tool's optional `describe()` (#43)
- A missing queue worker is named on the status line, with the command to run
- A decision that could not be applied says why
- Fixed Approve and Reject, which did nothing since 1.4
- Docs: the `TenantSet` listener a Shield app needs for roles in a worker
- Requires `packstub/agents` ^1.1

## 1.7.0 — 2026-09-09

The engine is now its own package, [packstub/agents](https://github.com/packstub/agents), which also runs in a plain Laravel app. This plugin requires it and keeps the panel parts.

- Classes keep their names; the config, env vars, commands and migrations are unchanged
- The plugin's provider is `Packstub\Agents\Filament\FilamentAgentsServiceProvider` (auto-discovered)
- A turn runs on the auth guard it was asked on

**Upgrading:** run `php artisan migrate` (a nullable `guard` column on `agent_turns`). `Agents::panel()` and `Agents::panelId()` moved to `FilamentContext`: `app(FilamentContext::class)->panel()`.

## 1.6.0 — 2026-09-09

- Prompt caching covers the whole stable prefix, so a long chat reads its settled turns from the cache (#14)
- Provider failover: `AGENT_FAILOVER=gemini,openai` tries the next provider when one refuses a turn (#30)
- Picker entries from more than one provider, such as Gemini Flash or a local Ollama model next to Claude (#38)
- The picker shows model names, such as Claude Opus 5
- The context meter is a ring next to Send, with a breakdown, Compress now and Continue in a new chat

A published config keeps its Auto / Fast / Deep labels; set `'label' => null` to show model names.

## 1.5.0 — 2026-09-09

- Every turn is recorded (model, tokens, tools, time, how it ended) and listed on a new **AI turns** page
- Agent middleware: add your own with `AgentsPlugin::make()->middleware([...])`
- A daily token budget per workspace (`AGENT_TOKENS_PER_DAY`)
- A refused question is kept, with the reason and a Retry

**Upgrading:** run `php artisan migrate` (new columns on `agent_turns` and `agent_limits`).

## 1.4.0 — 2026-09-08

- Answers run in a queued job and survive a reload, navigation or a closed tab
- Stop cuts the running answer short and keeps what was written
- Follow-ups wait their turn on the server, in every tab
- Regenerate and edit-and-resend on the last exchange
- Answers the provider ended early are marked "(cut short)"

**Upgrading:** run `php artisan migrate` and `php artisan filament:assets`. Run a queue worker, or set `AGENT_TURN_DRIVER=sync` to answer inside the request as before.

## 1.3.0 — 2026-09-08

- Long chats keep working: older messages fold into a rolling summary, with a context meter
- Continue in a new chat, seeded with a summary of the old one
- The composer never locks: questions typed while an answer runs are queued
- A question the provider could not answer is kept, with a Retry

**Upgrading:** run `php artisan migrate` (new `agent_conversation_summaries` table) and `php artisan filament:assets`.

## 1.2.0 — 2026-09-08

- Gemini and xAI out of the box (`AGENT_PROVIDER=gemini` or `xai`)
- Any other laravel/ai text provider (Ollama, OpenRouter, Mistral, Groq, DeepSeek…) works without config
- A narrower chat column, easier to read
- A simpler Create token modal: one table of tools
- The AI limits table fits a laptop screen

## 1.1.0 — 2026-09-04

- Scoped access tokens: limit a token to a few tools, and let it expire
- Helpers on `AgentTool` for apps that gate their own tools by token
- An MCP request without a valid token gets a JSON 401 instead of a 500

Existing tokens keep working unchanged.

## 1.0.1 — 2026-09-04

- Rejecting a proposal gives the model a reason, so the chat carries on
- The prompt names the tools as laravel/mcp registers them (`show-table`, `draw-chart`)
- An operator panel without the Agent access page no longer resets it on the tenant panel
- The Read / Write badges are translated

## 1.0.0 — 2026-09-03

First public release of Filament Agents, under the MIT license: one tool list for an in-panel chat and an MCP server.

- An "Ask …" button that brings the record you are viewing into the chat
- Read tools run directly; writes wait for your approval in the chat
- Live tables and charts in answers
- MCP over HTTP with Sanctum tokens from an Agent access page
- Budgets and limits per user and workspace, edited on an AI limits page
- Tenancy support, and German, Spanish, Romanian and Russian translations

**Upgrading from 0.x:** this is a rebuild. Move to `AgentTool`, the panel's own authorization and Sanctum tokens; see the docs.

Docs: https://packstub.dev/docs/filament-agents
