# Installation

## Requirements

| | |
| --- | --- |
| PHP | 8.4 or newer |
| Laravel | 13.x |
| Filament | 5.x |
| laravel/ai | ^0.11 |
| laravel/mcp | ^0.9 |
| laravel/sanctum | ^4 (tokens for MCP clients) |

The plugin requires [packstub/agents](https://packstub.dev/docs/agents) — the engine: `laravel/ai`, `laravel/mcp`, `laravel/sanctum`, the tools, the MCP server, the turn job, budgets and limits — so Composer installs it and them for you. The engine runs in a plain Laravel app too; this plugin is what a Filament panel adds on top.

## Install

```bash
composer require packstub/filament-agents
php artisan packstub-agents:install
php artisan filament:assets
```

The install command publishes `config/packstub-agents.php`, offers to run the migrations and scaffolds `app/Ai/Agents/Assistant.php`. The migrations create the `agent_limits` table and the chat tables (`agent_conversations`, `agent_conversation_messages`, `agent_message_feedback`, `agent_conversation_summaries`, `agent_turns`). They run from the package by default; a database-per-tenant app publishes and splits them, see [Tenancy](tenancy.md).

## A queue worker

The chat produces every answer in a queued job (`Packstub\Agents\Jobs\RunAgentTurn`), so the page never holds a request open while the model works and an answer keeps coming after a reload or in a second tab. Run a worker as you would for any queued job (`php artisan queue:work`, Horizon, Laravel Cloud's workers); `chat.queue_connection` and `chat.queue` pick where the jobs go.

A question that sits on the queue for `chat.worker_wait` seconds (10) without a worker taking it says so under the composer, with the command to run, instead of "Thinking…" until the job timeout.

No worker? Set `chat.driver` to `sync` (`AGENT_TURN_DRIVER=sync`, or `AgentsPlugin::make()->chat(driver: 'sync')`) and the job runs inside the request that asked, whatever the app's queue connection is — everything else the same, except that an answer dies with the tab that asked for it. See [The assistant](assistant.md#how-a-turn-runs).

## Sanctum

MCP clients authenticate with Sanctum personal access tokens, so your user model needs the `HasApiTokens` trait and the `personal_access_tokens` table:

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
}
```

```bash
php artisan vendor:publish --tag=sanctum-migrations
php artisan migrate
```

Skip this when you only want the in-panel chat and set `AGENT_MCP_ENABLED=false`.

## The theme

Filament v5 compiles plugin views into your panel's custom theme, so add the package views to it:

```css
@import '../../../../vendor/filament/filament/resources/css/theme.css';

@source '../../../../vendor/packstub/filament-agents/resources/views';
```

Then rebuild the theme (`npm run build` or `bun run build`). The package's own stylesheet (`agents.css`) is registered as a Filament asset and published by `filament:assets`.

## Register the plugin

```php
use App\Ai\Agents\Assistant;
use App\Mcp\Servers\AcmeServer;
use Packstub\Agents\AgentsPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // …
        ->plugin(
            AgentsPlugin::make()
                ->name('Ask Acme')
                ->agent(Assistant::class)
                ->server(AcmeServer::class)
                ->authorizeUsing(fn (string $ability) => auth()->user()->can($ability))
                ->roleLabelUsing(fn () => auth()->user()->role?->getLabel()),
        );
}
```

- `name()` is how the assistant introduces itself and what the topbar button says.
- `agent()` is your `Agent` subclass, see [The assistant](assistant.md).
- `server()` is the MCP server class that holds the tool list, see [Tools](tools.md). Without a server class, pass `->tools([...])` for a chat-only setup.
- `authorizeUsing()` tells the package how to check an ability for the current person. Without it, an ability goes through Laravel's `Gate` when a gate of that name exists and is otherwise allowed, so an app without abilities works out of the box.
- `roleLabelUsing()` gives the prompt and the refusal messages a role name ("Your role (Viewer) is not allowed to do this").

## The provider key

Provider credentials live in laravel/ai's `config/ai.php`, so the usual environment variables work:

```dotenv
AGENT_PROVIDER=anthropic
ANTHROPIC_API_KEY=sk-ant-…
```

or

```dotenv
AGENT_PROVIDER=openai
OPENAI_API_KEY=sk-…
```

or

```dotenv
AGENT_PROVIDER=gemini
GEMINI_API_KEY=AIza…
```

or

```dotenv
AGENT_PROVIDER=xai
XAI_API_KEY=xai-…
```

Those four have model picker entries out of the box (`auto`, `fast` and `deep`, shown by model name, see [Configuration](configuration.md#models)). Any other laravel/ai text provider works too — `AGENT_PROVIDER=ollama` for a local model, `openrouter`, `mistral`, `groq`, `deepseek` — with its key in `config/ai.php`; the picker then offers the provider's smartest and cheapest models, by name.

Without a key the chat hides itself (the pages, the topbar button and the sidebar) and the MCP endpoint keeps answering, since it does not need a model. `AGENT_ENABLED=false` hides the chat regardless.

## Write a first tool

```bash
php artisan packstub-agents:tool SearchOrders --ability=orders.view
```

Add it to the server's `$tools` and open the panel. The next page, [Tools](tools.md), explains what goes into a tool.
