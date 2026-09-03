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

The package requires `laravel/ai`, `laravel/mcp` and `laravel/sanctum`, so Composer installs them for you.

## Install

```bash
composer require packstub/filament-agents
php artisan packstub-agents:install
php artisan filament:assets
```

The install command publishes `config/packstub-agents.php`, offers to run the migrations and scaffolds `app/Ai/Agents/Assistant.php`. The migrations create the `agent_limits` table and the chat tables (`agent_conversations`, `agent_conversation_messages`, `agent_message_feedback`). They run from the package by default; a database-per-tenant app publishes and splits them, see [Tenancy](tenancy.md).

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

Without a key the chat hides itself (the pages, the topbar button and the sidebar) and the MCP endpoint keeps answering, since it does not need a model. `AGENT_ENABLED=false` hides the chat regardless.

## Write a first tool

```bash
php artisan packstub-agents:tool SearchOrders --ability=orders.view
```

Add it to the server's `$tools` and open the panel. The next page, [Tools](tools.md), explains what goes into a tool.
