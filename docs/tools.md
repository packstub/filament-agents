# Tools

Every capability the assistant has is one `laravel/mcp` tool class. The MCP server lists it to external agents; the in-panel chat calls the very same class through laravel/ai's `McpServerTool` bridge. There is one list, and it lives on your server class.

## Scaffold

```bash
php artisan packstub-agents:tool SearchOrders --ability=orders.view
php artisan packstub-agents:tool ConfirmOrder --write --ability=orders.manage
```

The command writes `app/Mcp/Tools/<Name>.php`. Without `--write` the class carries `#[IsReadOnly]`; `--ability` fills the `$ability` property.

## Anatomy

```php
namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Packstub\Agents\Mcp\AgentTool;

#[IsReadOnly]
#[Description('Find orders by number, customer, status and date. Returns compact rows with a url.')]
class SearchOrders extends AgentTool
{
    /** The ability required to see and run this tool; null = any member of the workspace. */
    protected ?string $ability = 'orders.view';

    protected function run(Request $request): array
    {
        $query = Order::query()
            ->when($request->get('query'), fn ($q, $text) => $q->where('number', 'like', "%{$text}%"));

        return [
            'total' => $query->count(),
            'rows' => $query->limit($this->limit($request))->get()->map(fn (Order $order) => [
                'number' => $order->number,
                'status' => $order->status->value,
                'url' => OrderResource::getUrl('view', ['record' => $order]),
            ])->all(),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Order number or customer name.'),
            'limit' => $schema->integer()->description('Max rows, default 20.'),
        ];
    }
}
```

- `$ability` is the same string that gates the panel resource or action the tool mirrors. The tool is only listed, and only runs, when the current person may that ability (through the `authorizeUsing()` callback, or the `Gate`).
- `run()` returns the data the model gets to see; it is encoded as JSON. Keep rows compact and always include a `url` so the answer can link to the record.
- `schema()` describes the arguments with laravel's `JsonSchema` builder. Use `#[Description]` for what the tool does, when to use it and what it returns; the model reads it.
- `limit()` clamps a requested page size (default 20, max 50).

## Read-only versus write

`#[IsReadOnly]` decides how a tool is treated in both places:

| | Read-only tool | Write tool |
| --- | --- | --- |
| In the chat | runs directly | wrapped as an `ApprovableTool`: the person sees the tool and its arguments and approves or rejects it before it runs |
| Over MCP with a `read` token | runs | refused ("This access token is read-only.") |
| Over MCP with a `write` token | runs | runs directly with the token holder's role |

There is no separate "destructive" tier: a write is a write. If a change needs extra care, say so in the description, read the record first inside `run()` and refuse when the state is wrong.

## Errors

Exceptions thrown from `run()` are handed back to the model as tool errors, never to the person as a crash:

| Thrown | The model sees |
| --- | --- |
| `Illuminate\Validation\ValidationException` (from `$request->validate([...])`) | "Invalid arguments: …" |
| `RuntimeException`, `InvalidArgumentException` | the exception message |
| anything else | "The action failed: …", and the exception is reported |

So a domain service that throws `RuntimeException('Order RO-00012 is already shipped.')` produces a sentence the assistant can relay and act on.

When the person's role does not allow the tool, the model gets "Your role (Viewer) is not allowed to do this." (or "You are not allowed to do this." without a role label), and the generic rules tell it to say who can do it instead of retrying.

## The server class

```php
namespace App\Mcp\Servers;

use App\Mcp\Tools;
use Packstub\Agents\Mcp\AgentServer;
use Packstub\Agents\Mcp\Tools\DrawChart;
use Packstub\Agents\Mcp\Tools\ShowTable;

class AcmeServer extends AgentServer
{
    protected string $name = 'Acme';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        The back office of an online shop. Start with search-orders; confirm-order and ship-order change data.
        MARKDOWN;

    protected array $tools = [
        Tools\WorkspaceOverview::class,
        Tools\SearchOrders::class,
        ShowTable::class,
        DrawChart::class,
        Tools\ConfirmOrder::class,
        Tools\ShipOrder::class,
    ];
}
```

Register it with `AgentsPlugin::make()->server(AcmeServer::class)`. The chat agent reads the same `$tools`, in the same order, so put the reads first and the overview tool at the top: the generic rules tell the assistant to start broad questions with the overview tool when there is one.

`ShowTable` and `DrawChart` are the package's own tools, see [Tables and charts](tables-and-charts.md). Include them when you want live tables and charts in answers.

A chat-only app may skip the server class and pass the list to the plugin: `AgentsPlugin::make()->tools([...])`. The package's default `AgentServer` then serves that list under the assistant's name.

## Sharing filters with the panel

When a resource implements `AgentResource` (see [Tables and charts](tables-and-charts.md)), its filter vocabulary is available to your search tools too, so "orders waiting for a phone call" means the same thing whether the model searches or shows a table:

```php
protected function run(Request $request): array
{
    $filters = AgentResources::normalizeFilters('orders', (array) ($request->get('filters') ?? []));
    $query = AgentResources::apply('orders', Order::query(), $filters);
    // …
}

public function schema(JsonSchema $schema): array
{
    return [
        'filters' => $schema->object(AgentResources::filterSchema($schema, 'orders')),
        'limit' => $schema->integer(),
    ];
}
```

## Tools that return a chart

Any tool may return a `chart` key next to its data, and the chat renders it under the answer:

```php
return [
    'chart' => [
        'type' => 'line',
        'title' => 'Orders per day',
        'labels' => $days,
        'datasets' => [['label' => 'Orders', 'data' => $counts]],
    ],
    'total' => array_sum($counts),
];
```

`type` is one of `bar`, `line`, `pie` or `doughnut`. Prefer this over `draw-chart` for anything over time: the numbers come straight from the query.
