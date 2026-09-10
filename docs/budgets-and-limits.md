# Budgets and limits

A chat that calls a frontier model on every question needs a ceiling. The package counts what laravel/ai already stores with every assistant message (the provider's token usage) and refuses a turn before it reaches the provider when a limit is hit. The provider's own spend limit stays the real backstop.

## The limits

| Limit | Scope | Config key / env |
| --- | --- | --- |
| Questions per minute | per user | `turns_per_minute` / `AGENT_TURNS_PER_MINUTE` (6) |
| Answers per day | per workspace | `turns_per_day` / `AGENT_TURNS_PER_DAY` (150) |
| Tokens per day | per workspace, all token kinds | `tokens_per_day` / `AGENT_TOKENS_PER_DAY` (600,000) |
| Tokens per month | per workspace, all token kinds | `tokens_per_month` / `AGENT_TOKENS_PER_MONTH` (3,000,000) |
| Tokens per day | per user, inside the workspace | `user_tokens_per_day` / `AGENT_USER_TOKENS_PER_DAY` (100,000) |
| Tokens per month | per user, inside the workspace | `user_tokens_per_month` / `AGENT_USER_TOKENS_PER_MONTH` (1,500,000) |
| Max question length | characters | `prompt_max_chars` / `AGENT_PROMPT_MAX_CHARS` (2,000) |

`null` (or `0` in the environment) disables a limit. The values in `config/packstub-agents.php` are the platform's ceiling; the operator page below overrides them.

When a turn is refused nothing is sent to the provider. The question stays in the chat with the reason under it ("This workspace reached today's limit of 150 answers. It resets at midnight.") and a Retry, like a question the provider could not answer, so nothing typed is lost and it can be sent again — or edited first — once the limit allows. The `EnforceBudget` [middleware](assistant.md#middleware) makes the check when the turn runs, and counts it, so the limits hold for every turn however it was started — a follow-up that waited in the queue, a console command, an app that prompts the agent directly.

## The AI limits resource

An operator panel (the central panel of a SaaS, or the admin panel of a single app) registers the limits resource:

```php
AgentsPlugin::make()
    ->chat(false)
    ->agentAccess(false)
    ->limits(authorize: fn () => (bool) auth()->user()?->is_admin)
```

![The AI limits resource on an operator panel: platform defaults, two workspace rows and one user switched off](https://raw.githubusercontent.com/packstub/filament-agents/main/docs/images/ai-limits.png)

**AI limits** then lists rows with three scopes:

| Scope | Applies to | Fields |
| --- | --- | --- |
| Everyone (global) | every workspace and user | all of them, plus an on/off switch |
| One workspace | one tenant (only offered in a panel with tenancy) | all of them |
| One user | one account, in every workspace | the per-user fields: on/off, questions per minute, tokens per day and per month, max question length |

The table shows the workspace columns (Assistant, Answers / day, Tokens / month, Note) by default; the per-user detail (/ min, User tokens / day and / month, Max chars) is behind the column toggle, so the table fits a laptop screen.

Empty fields inherit: user → workspace → everyone → the config defaults. The **Assistant** switch on a workspace row turns the chat off for that workspace entirely (the pages and buttons hide themselves); on a user row it does the same for one person.

Rows live in the `agent_limits` table on `packstub-agents.limits_connection` (`AGENT_LIMITS_CONNECTION`), the central connection in a database-per-tenant app, since limits are the operator's, not the workspace's. Resolved limits are cached for the request; the resource flushes the cache after every edit.

## In code

```php
use Packstub\Agents\Support\AgentBudget;
use Packstub\Agents\Support\AgentLimits;

AgentLimits::effective();          // the merged limits for the current tenant and user
AgentLimits::effective($tenant, $user);
AgentBudget::refusal($prompt);     // the reason a turn may not start, or null
AgentBudget::summary();            // turns today, tokens this month, per-user counters and their limits
```

`AgentBudget::summary()` is what a workspace settings page shows next to "your AI usage this month". Every counter comes from `agent_conversation_messages`, so no extra bookkeeping is needed.

A guard rail of your own (a plan without the assistant, a frozen workspace) is a [middleware](assistant.md#middleware) that throws `TurnRefused` with the message the person should read.

## What each turn cost

Every turn leaves a record on its `agent_turns` row when it ends: the provider and model that answered, the token usage the provider reported (prompt, completion, cache reads and writes, reasoning), the tools called in order, the wall time, and how it ended — `stop`, `length`, `content_filter` or `dropped` from the provider, `stopped` by the person, `refused` by a middleware, `failed` by an error or a lost worker.

### The AI turns page

The operator panel that registers `limits()` also gets **AI turns** (`/agent-turns`): one row per turn, newest first, with who asked (and in which workspace, in a panel with tenancy), the status, model and provider, tokens in and out, the number of tools called (the names on hover), the duration and how it ended; the error message and the turn id are behind the column toggle. Filter by status or provider. The page is gated like the limits resource (`limits(authorize: …)`).

```php
AgentsPlugin::make()->limits()                     // AI limits and AI turns
AgentsPlugin::make()->limits()->turnLog(false)     // AI limits only
AgentsPlugin::make()->limits(false, authorize: fn () => auth()->user()->is_admin)->turnLog()   // AI turns only
```

The rows live with the conversations (`ai.conversations.connection`). In a database-per-tenant app that is the tenant database, so register the page on the tenant panel with the third form above rather than on the central one.

Ended turns are kept for `chat.keep_turns_days` (`AGENT_KEEP_TURNS_DAYS`, 90; `null` keeps them forever) and pruned by Laravel's model pruning — add the model to your schedule:

```php
Schedule::command('model:prune', ['--model' => [\Packstub\Agents\Models\AgentTurn::class]])->daily();
```

### The log line

Set `log.channel` (`AGENT_LOG_CHANNEL`) to a channel from `config/logging.php` and every ended turn writes one info line there — `Agent turn done: anthropic/claude-opus-5, 1,240 tokens in, 310 out, 2 tool calls, 4.2 s, ended stop` — with the whole record in the context (`turn`, `conversation`, `user`, `tenant`, `panel`, `status`, `provider`, `model`, `model_key`, the five token counts, `tool_calls`, `duration_ms`, `finish_reason`, `error`). Point it at a JSON channel for your log platform, or at `stack` to keep it with the app log. `null` (the default) logs nothing; the row and the page carry the record either way.

For anything beyond that — cost per team, alerts, an audit trail with the prompt — write a [middleware](assistant.md#middleware) and read the response in its `then()` callback.
