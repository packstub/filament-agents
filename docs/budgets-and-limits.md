# Budgets and limits

A chat that calls a frontier model on every question needs a ceiling. The package counts what laravel/ai already stores with every assistant message (the provider's token usage) and refuses a turn before it reaches the provider when a limit is hit. The provider's own spend limit stays the real backstop.

## The limits

| Limit | Scope | Config key / env |
| --- | --- | --- |
| Questions per minute | per user | `turns_per_minute` / `AGENT_TURNS_PER_MINUTE` (6) |
| Answers per day | per workspace | `turns_per_day` / `AGENT_TURNS_PER_DAY` (150) |
| Tokens per month | per workspace, all token kinds | `tokens_per_month` / `AGENT_TOKENS_PER_MONTH` (3,000,000) |
| Tokens per day | per user, inside the workspace | `user_tokens_per_day` / `AGENT_USER_TOKENS_PER_DAY` (100,000) |
| Tokens per month | per user, inside the workspace | `user_tokens_per_month` / `AGENT_USER_TOKENS_PER_MONTH` (1,500,000) |
| Max question length | characters | `prompt_max_chars` / `AGENT_PROMPT_MAX_CHARS` (2,000) |

`null` (or `0` in the environment) disables a limit. The values in `config/packstub-agents.php` are the platform's ceiling; the operator page below overrides them.

When a turn is refused the person sees the reason in the chat ("This workspace reached today's limit of 150 answers. It resets at midnight.") and nothing is sent to the provider.

## The AI limits resource

An operator panel (the central panel of a SaaS, or the admin panel of a single app) registers the limits resource:

```php
AgentsPlugin::make()
    ->chat(false)
    ->agentAccess(false)
    ->limits(authorize: fn () => (bool) auth()->user()?->is_admin)
```

**AI limits** then lists rows with three scopes:

| Scope | Applies to | Fields |
| --- | --- | --- |
| Everyone (global) | every workspace and user | all of them, plus an on/off switch |
| One workspace | one tenant (only offered in a panel with tenancy) | all of them |
| One user | one account, in every workspace | the per-user fields: on/off, questions per minute, tokens per day and per month, max question length |

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
