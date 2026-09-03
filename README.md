# Packstub Agents

An in-panel AI assistant and an MCP server for Filament v5 panels, built on `laravel/ai` and `laravel/mcp`.

- **One tool list, two front doors.** Every capability is one `laravel/mcp` tool. The in-panel chat calls it through laravel/ai's bridge; Claude Code, Claude Desktop or any MCP client calls it over HTTP with a token from the panel. Add a tool once and it is everywhere.
- **Authorization is the panel's.** A tool declares the same ability string that gates the resource or action it mirrors. The assistant can never do more than the person could by hand; a read-only token adds a second gate for external agents.
- **Writes are approved where the human is.** In the chat, a write tool is a proposal with Approve / Reject (laravel/ai approvals). Over MCP, a write token runs it directly with the person's role.
- **Answers that show the real thing.** `show_table` renders the resource's own Filament table under the answer — same columns, filters, sorting and row actions. A tool result with a `chart` key becomes a Chart.js chart. Page context tells the assistant which record the person opened the chat from.
- **A bounded bill.** Per-user burst limit, per-workspace answers per day and tokens per month, per-user tokens per day and month, prompt length — checked before a turn reaches the provider, editable per workspace and per user on an operator page. A workspace can bring its own provider key.
- **Tenancy-aware.** The MCP path carries the workspace, tokens are bound to it, conversations can live in the tenant database. Works without tenancy too.

## Requirements

PHP 8.4+, Laravel 13, Filament v5, `laravel/ai` ^0.11, `laravel/mcp` ^0.9, `laravel/sanctum` ^4 (tokens).

## Installation

```bash
composer require packstub/filament-agents
php artisan packstub-agents:install   # config, migrations, app/Ai/Agents/Assistant.php
php artisan filament:assets
```

Add the package views to your panel theme so Tailwind picks up their classes (Filament v5 needs a custom theme for plugin views):

```css
@source '../../../../vendor/packstub/filament-agents/resources/views';
```

Register the plugin in the panel provider:

```php
use Packstub\Agents\AgentsPlugin;

->plugin(
    AgentsPlugin::make()
        ->name('Ask Orderflux')
        ->agent(\App\Ai\Agents\Orderflux::class)
        ->server(\App\Mcp\Servers\OrderfluxServer::class)
        ->authorizeUsing(fn (string $ability) => Access::can($ability))
        ->roleLabelUsing(fn () => Access::role()?->getLabel())
        ->agentAccess(ability: 'setup.view', group: fn () => __('Setup')),
)
```

Put a provider key in `.env` (`ANTHROPIC_API_KEY` or `OPENAI_API_KEY`, with `AGENT_PROVIDER=anthropic|openai`). Without a key the chat hides itself and the MCP endpoint still answers.

## Writing tools

```bash
php artisan packstub-agents:tool SearchOrders --ability=orders.view
php artisan packstub-agents:tool ConfirmOrder --write --ability=orders.manage
```

A tool extends `Packstub\Agents\Mcp\AgentTool`, declares its `$ability`, implements `run(Request): array` and `schema(JsonSchema): array`. Mark reads with `#[IsReadOnly]`; anything else is approval-gated in the chat and needs a write token over MCP. Domain exceptions (`RuntimeException`, `InvalidArgumentException`, validation) come back to the model as tool errors.

List the tools on the server class, reads first:

```php
class OrderfluxServer extends \Packstub\Agents\Mcp\AgentServer
{
    protected string $name = 'Orderflux';
    protected string $instructions = '…';

    protected array $tools = [
        Tools\WorkspaceOverview::class,
        Tools\SearchOrders::class,
        \Packstub\Agents\Mcp\Tools\ShowTable::class,
        \Packstub\Agents\Mcp\Tools\DrawChart::class,
        Tools\ConfirmOrder::class,
    ];
}
```

The chat agent reads the same list. `AgentsPlugin::make()->tools([...])` works instead of a server class when you only want the chat.

## The agent

`packstub-agents:agent` scaffolds `App\Ai\Agents\Assistant`, a subclass of `Packstub\Agents\Ai\Agent` with two slots to fill: `persona()` (who it is) and `domain()` (what the workspace is). The base class supplies the generic working and answering rules, the dynamic context (date, workspace, person, role, language, page context) and the provider options (Anthropic prompt caching of the static block, reasoning effort). Append to any of them by overriding `workRules()`, `answerRules()` or `context()` and merging the parent's list.

## Live tables and page context

A Filament resource opts in by implementing `AgentResource` with the `InteractsWithAgent` trait. Defaults come from the resource itself; override what the domain needs:

```php
class OrderResource extends Resource implements AgentResource
{
    use InteractsWithAgent;

    public static function agentSummary(Model $record, bool $full = false): array
    {
        return Present::order($record, $full);   // what the model sees, always with 'url'
    }

    public static function agentContextLabel(Model $record): string
    {
        return __('Order :number', ['number' => $record->number]);
    }

    public static function agentFilters(): array
    {
        return [
            Filter::text('query')->description('Order number or customer.')
                ->apply(fn (Builder $q, string $t) => $q->where('number', 'like', "%{$t}%")),
            Filter::enum('status', OrderStatus::class)->multiple()
                ->apply(fn (Builder $q, array $s) => $q->whereIn('status', $s)),
            Filter::flag('open_only')
                ->apply(fn (Builder $q) => $q->whereIn('status', [...])),
            Filter::date('placed_from')
                ->apply(fn (Builder $q, string $d) => $q->where('placed_at', '>=', $d)),
        ];
    }
}
```

From that, `show_table` builds its schema (tables and filters), the embedded table applies the same filters, and the topbar button carries the current record into the chat. Your search tools can reuse it: `AgentResources::filterSchema($schema, 'orders')` for the schema and `AgentResources::apply('orders', $query, AgentResources::normalizeFilters('orders', $request->all()))` for the query.

## Budgets and the operator page

`config/packstub-agents.php` holds the platform ceiling (`AGENT_TURNS_PER_MINUTE`, `AGENT_TURNS_PER_DAY`, `AGENT_TOKENS_PER_MONTH`, `AGENT_USER_TOKENS_PER_DAY`, `AGENT_USER_TOKENS_PER_MONTH`, `AGENT_PROMPT_MAX_CHARS`). An operator panel registers

```php
->plugin(AgentsPlugin::make()->chat(false)->agentAccess(false)->limits(authorize: fn () => auth()->user()?->is_admin))
```

to get the **AI limits** resource: one global row, optional rows per workspace and per user, empty fields inherit. `AgentBudget::summary()` gives the numbers for a settings page.

## A workspace's own key

Tell the plugin where a workspace's provider, key and preferred model come from:

```php
->credentialsUsing(fn () => tenant()
    ? new WorkspaceCredentials(tenant_settings()->assistantProvider(), tenant_settings()->assistantApiKey(), tenant_settings()->assistantModel())
    : null)
```

## Tenancy

Set the MCP path with the workspace in it: `'mcp' => ['path' => 'mcp/{tenant}']`. The middleware looks the workspace up by the panel's tenant slug, checks the person's membership and the token's `tenant:{slug}` ability, and sets it on Filament — which fires `TenantSet`, so `packstub/filament-tenancy` (or any listener) switches the database exactly as for a page.

For a database-per-tenant app set `'run_migrations' => false`, publish the migrations (`vendor:publish --tag=packstub-agents-migrations`), keep `create_agent_limits_table` central and move `create_agent_chat_tables` next to your tenant migrations; point `limits_connection` at the central connection.

## Testing

Fake the model with `YourAgent::fake([...])`; drive tools through `YourServer::tool(ToolClass::class, [...])`. Never call a provider from tests.

## License

Proprietary — see [LICENSE.md](LICENSE.md). Sold via [packstub.dev](https://packstub.dev).
