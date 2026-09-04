# Filament Agents

<div class="filament-hidden">

![Filament Agents — an in-panel AI assistant and an MCP server for Filament panels](https://raw.githubusercontent.com/packstub/filament-agents/main/art/banner.jpg)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/packstub/filament-agents.svg?style=flat-square)](https://packagist.org/packages/packstub/filament-agents)
[![Tests](https://img.shields.io/github/actions/workflow/status/packstub/filament-agents/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/packstub/filament-agents/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/packstub/filament-agents.svg?style=flat-square)](https://packagist.org/packages/packstub/filament-agents)
[![License](https://img.shields.io/packagist/l/packstub/filament-agents.svg?style=flat-square)](https://github.com/packstub/filament-agents/blob/main/LICENSE.md)

</div>

An in-panel AI assistant and an MCP server for your Filament v5 panel, built on [laravel/ai](https://github.com/laravel/ai) and [laravel/mcp](https://github.com/laravel/mcp). Write a tool once and it serves both the chat inside the panel and Claude Code, Claude Desktop, Cursor or any other MCP client, with the panel's own authorization deciding who may run it.

## Features

- **[One tool list, two front doors](#writing-tools)** — every capability is a `laravel/mcp` tool. The in-panel chat calls it through laravel/ai's bridge; external agents call it over HTTP with a token minted in the panel. Add a tool to the list and it is everywhere.
- **[Authorization is the panel's](#writing-tools)** — a tool declares the ability string that gates the resource or action it mirrors. The assistant can never do more than the signed-in person could by hand, and a token narrows that further for external agents: read-only, or just the tools they need.
- **[Writes are approved where the human is](#the-chat)** — in the chat, a write tool is a proposal with Approve and Reject buttons (laravel/ai approvals). Over MCP, a write token runs it directly with the person's role.
- **[Answers that show the real thing](#live-tables-and-charts)** — `show-table` renders the resource's own Filament table under the answer, with its search, filters, sorting and row actions. A tool result with a `chart` key becomes a chart. Page context tells the assistant which record the person opened the chat from.
- **[A bounded bill](#budgets-and-the-operator-page)** — a per-user burst limit, answers per day and tokens per month per workspace, tokens per day and per month per user, and a prompt length cap, all checked before a turn reaches the provider and editable per workspace and per user on an operator page.
- **[Your assistant, your prompt](#the-assistant)** — a scaffolded agent class with two slots to fill (who it is, what the workspace is) on top of generic working and answering rules, provider-cached instructions and a model picker (Auto, Fast, Deep) for Anthropic or OpenAI.
- **[Tenancy-aware](#tenancy)** — the MCP path can carry the workspace, tokens are bound to it, conversations can live in the tenant database and a workspace can bring its own provider key. Works without tenancy too.
- **Translatable** — every string goes through `__()`, with German, Spanish, Romanian and Russian included.

## Compatibility

| Plugin | Filament | Laravel | PHP | laravel/ai | laravel/mcp |
| --- | --- | --- | --- | --- | --- |
| 1.x | 5.x | 13.x | 8.4+ | ^0.11 | ^0.9 |

## Installation

```bash
composer require packstub/filament-agents
php artisan packstub-agents:install
php artisan filament:assets
```

The install command publishes the config, runs the migrations and scaffolds `app/Ai/Agents/Assistant.php`. Filament v5 only compiles plugin views into a custom theme, so add the package views to yours:

```css
@source '../../../../vendor/packstub/filament-agents/resources/views';
```

Register the plugin in your panel provider and put a provider key in `.env` (`ANTHROPIC_API_KEY` or `OPENAI_API_KEY`, with `AGENT_PROVIDER=anthropic|openai`):

```php
use Packstub\Agents\AgentsPlugin;

->plugin(
    AgentsPlugin::make()
        ->name('Ask Acme')
        ->agent(\App\Ai\Agents\Assistant::class)
        ->server(\App\Mcp\Servers\AcmeServer::class)
        ->authorizeUsing(fn (string $ability) => auth()->user()->can($ability)),
)
```

Without a key the chat hides itself and the MCP endpoint still answers. Read more: [Installation](https://packstub.dev/docs/filament-agents/installation).

## Writing tools

```bash
php artisan packstub-agents:tool SearchOrders --ability=orders.view
php artisan packstub-agents:tool ConfirmOrder --write --ability=orders.manage
```

A tool extends `Packstub\Agents\Mcp\AgentTool`, declares its `$ability`, and implements `run(Request): array` and `schema(JsonSchema): array`. Mark reads with `#[IsReadOnly]`; anything else is approval-gated in the chat and needs a write token over MCP. Domain exceptions come back to the model as tool errors, never to the person as a crash.

```php
#[IsReadOnly]
#[Description('Find orders by number, customer, status and date.')]
class SearchOrders extends AgentTool
{
    protected ?string $ability = 'orders.view';

    protected function run(Request $request): array
    {
        $filters = AgentResources::normalizeFilters('orders', (array) $request->get('filters'));
        $query = AgentResources::apply('orders', Order::query(), $filters);

        return [
            'total' => $query->count(),
            'rows' => $query->limit($this->limit($request))->get()->map(fn (Order $o) => OrderResource::agentSummary($o))->all(),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'filters' => $schema->object(AgentResources::filterSchema($schema, 'orders')),
            'limit' => $schema->integer(),
        ];
    }
}
```

List the tools on the server class, reads first. The chat agent reads the same list:

```php
class AcmeServer extends \Packstub\Agents\Mcp\AgentServer
{
    protected string $name = 'Acme';

    protected string $instructions = 'The back office of an online shop. Start with search-orders.';

    protected array $tools = [
        Tools\SearchOrders::class,
        \Packstub\Agents\Mcp\Tools\ShowTable::class,
        \Packstub\Agents\Mcp\Tools\DrawChart::class,
        Tools\ConfirmOrder::class,
    ];
}
```

`AgentsPlugin::make()->tools([...])` works instead of a server class when you only want the chat. Read more: [Tools](https://packstub.dev/docs/filament-agents/tools).

## The chat

The assistant lives on a chat page with an "Ask …" button in the topbar and the recent conversations in the sidebar. Answers stream in while the agent calls tools; a proposed change shows up as a card with Approve and Reject, and the turn resumes with the decision. Conversations are stored with laravel/ai's models, so a reload never loses anything, and every answer can be rated with a thumbs up or down. A model picker next to the composer offers Auto, Fast and Deep, remembered per session.

![The chat: an answer with a live orders table under it, then a Confirm Order proposal with Approve and Reject buttons](https://raw.githubusercontent.com/packstub/filament-agents/main/docs/images/chat.png)

Read more: [The assistant](https://packstub.dev/docs/filament-agents/assistant).

## The assistant

`packstub-agents:agent` scaffolds `App\Ai\Agents\Assistant`, a subclass of `Packstub\Agents\Ai\Agent` with two slots to fill: `persona()` (who it is) and `domain()` (what the workspace is). The base class supplies the generic working and answering rules, the dynamic context (date, workspace, person, role, language, page context) and the provider options (Anthropic prompt caching of the static block, reasoning effort per model). Append to any of them by overriding `workRules()`, `answerRules()` or `context()` and merging the parent's list.

```php
class Assistant extends Agent
{
    protected function persona(): string
    {
        return 'You are Ask Acme, the back-office assistant of an online shop.';
    }

    protected function domain(): string
    {
        return <<<'PROMPT'
        - Orders move from placed to paid to shipped; a cancelled order keeps its number.
        - Warehouse staff may confirm and ship; only managers may refund.
        PROMPT;
    }
}
```

## Live tables and charts

A Filament resource opts in by implementing `AgentResource` with the `InteractsWithAgent` trait. Defaults come from the resource itself; override what the domain needs:

```php
class OrderResource extends Resource implements AgentResource
{
    use InteractsWithAgent;

    public static function agentSummary(Model $record, bool $full = false): array
    {
        return ['number' => $record->number, 'status' => $record->status, 'url' => static::agentRecordUrl($record)];
    }

    public static function agentFilters(): array
    {
        return [
            Filter::text('query')->description('Order number or customer.')
                ->apply(fn (Builder $q, string $t) => $q->where('number', 'like', "%{$t}%")),
            Filter::enum('status', OrderStatus::class)->multiple()
                ->apply(fn (Builder $q, array $s) => $q->whereIn('status', $s)),
            Filter::date('placed_from')
                ->apply(fn (Builder $q, string $d) => $q->where('placed_at', '>=', $d)),
        ];
    }
}
```

From that, `show-table` builds its schema, the embedded table applies the same filters, and the topbar button carries the current record into the chat as page context. `draw-chart` renders bar, line, pie and doughnut charts from numbers the model already retrieved, and any tool can return a `chart` key of its own.

![The Orders resource with the Ask Acme button in the topbar](https://raw.githubusercontent.com/packstub/filament-agents/main/docs/images/orders-ask-button.png)

Read more: [Tables and charts](https://packstub.dev/docs/filament-agents/tables-and-charts).

## MCP clients

An **Agent access** page lets a person mint a token for Claude Code, Claude Desktop, Cursor or any MCP client: read or read-and-write, optionally limited to a few named tools, optionally expiring. The token is shown once, carries the workspace when the panel has tenancy, and can be revoked from the same page. The MCP endpoint is `POST /mcp` by default, behind `throttle`, `auth:sanctum` and the package's own middleware, so external agents get exactly the tools the person's role and their token allow.

![The Agent access page listing two tokens: one scoped to three tools and expiring, one read-only](https://raw.githubusercontent.com/packstub/filament-agents/main/docs/images/agent-access.png)

![The Create token modal: read and write abilities, an expiry, and the tool picker with read and write tools apart](https://raw.githubusercontent.com/packstub/filament-agents/main/docs/images/create-token.png)

Read more: [MCP clients](https://packstub.dev/docs/filament-agents/mcp-clients).

## Budgets and the operator page

`config/packstub-agents.php` holds the platform ceiling (`AGENT_TURNS_PER_MINUTE`, `AGENT_TURNS_PER_DAY`, `AGENT_TOKENS_PER_MONTH`, `AGENT_USER_TOKENS_PER_DAY`, `AGENT_USER_TOKENS_PER_MONTH`, `AGENT_PROMPT_MAX_CHARS`). An operator panel registers

```php
->plugin(AgentsPlugin::make()->chat(false)->agentAccess(false)->limits(authorize: fn () => auth()->user()?->is_admin))
```

to get the **AI limits** resource: one global row, optional rows per workspace and per user, empty fields inherit. `AgentBudget::summary()` gives the numbers for a settings page.

![The AI limits resource on an operator panel: platform defaults, two workspace rows and one user switched off](https://raw.githubusercontent.com/packstub/filament-agents/main/docs/images/ai-limits.png)

Read more: [Budgets and limits](https://packstub.dev/docs/filament-agents/budgets-and-limits).

## Tenancy

Set the MCP path with the workspace in it (`'mcp' => ['path' => 'mcp/{tenant}']`). The middleware looks the workspace up by the panel's tenant slug, checks the person's membership and the token's `tenant:{slug}` ability, and sets it on Filament, which fires `TenantSet`, so [Filament Tenancy](https://packstub.dev/plugins/filament-tenancy) or any listener switches the database exactly as for a page. A workspace can bring its own provider key through `credentialsUsing()`, and per-workspace limits live on the operator page.

For a database-per-tenant app set `'run_migrations' => false`, publish the migrations, keep `create_agent_limits_table` central and move `create_agent_chat_tables` next to your tenant migrations.

Read more: [Tenancy](https://packstub.dev/docs/filament-agents/tenancy).

## Configuration

```php
AgentsPlugin::make()
    ->name('Ask Acme')                                   // how the assistant is called in the panel
    ->agent(Assistant::class)                            // your Agent subclass
    ->server(AcmeServer::class)                          // the MCP server class with the tool list
    ->tools([...])                                       // or a plain tool list, chat only
    ->resources([OrderResource::class])                  // explicit AgentResource list (default: discovered)
    ->authorizeUsing(fn (string $ability) => ...)        // how an ability is checked for the current person
    ->roleLabelUsing(fn () => ...)                       // the person's role, for the prompt and refusals
    ->credentialsUsing(fn () => new WorkspaceCredentials(...)) // a workspace's own provider key
    ->chat(true)                                         // the chat pages, the Ask button and recent chats
    ->agentAccess(ability: 'setup.view', group: 'Setup') // the token page
    ->limits(authorize: fn () => ...)                    // the operator's AI limits resource
    ->hideAskButtonOn(['*.pages.dashboard']);            // route patterns without the topbar button
```

Read more: [Configuration](https://packstub.dev/docs/filament-agents/configuration).

## Documentation

- [Installation](https://packstub.dev/docs/filament-agents/installation)
- [Tools](https://packstub.dev/docs/filament-agents/tools)
- [The assistant](https://packstub.dev/docs/filament-agents/assistant)
- [Tables and charts](https://packstub.dev/docs/filament-agents/tables-and-charts)
- [MCP clients](https://packstub.dev/docs/filament-agents/mcp-clients)
- [Budgets and limits](https://packstub.dev/docs/filament-agents/budgets-and-limits)
- [Tenancy](https://packstub.dev/docs/filament-agents/tenancy)
- [Configuration](https://packstub.dev/docs/filament-agents/configuration)
- [Security](https://packstub.dev/docs/filament-agents/security)
- [Testing](https://packstub.dev/docs/filament-agents/testing)

## Testing

```bash
composer test
```

In your own app, fake the model with `Assistant::fake([...])` and drive tools through `AcmeServer::tool(ToolClass::class, [...])`. Never call a provider from tests. Read more: [Testing](https://packstub.dev/docs/filament-agents/testing).

## Changelog

See the [changelog](https://github.com/packstub/filament-agents/blob/main/CHANGELOG.md).

## Security vulnerabilities

This package lets a model act inside your panel, so we take reports seriously. Please e-mail [support@packstub.dev](mailto:support@packstub.dev) rather than opening a public issue. The threat model is documented on the [Security](https://packstub.dev/docs/filament-agents/security) page.

## Credits

- [Ion Caliman](https://github.com/icaliman)
- [All contributors](https://github.com/packstub/filament-agents/contributors)

## License

MIT. See the [license file](https://github.com/packstub/filament-agents/blob/main/LICENSE.md).
