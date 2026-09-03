# Testing

Never call a provider from tests. laravel/ai fakes the model, laravel/mcp drives tools directly, and the HTTP endpoint is a normal route.

## Faking the model

```php
use App\Ai\Agents\Assistant;
use Packstub\Agents\Filament\Pages\Chat;

use function Pest\Livewire\livewire;

it('answers in the chat', function () {
    actingAs($user);
    Assistant::fake(['Two orders are waiting for a call.']);

    livewire(Chat::class)
        ->set('prompt', 'What needs attention?')
        ->call('send');

    $conversation = Conversation::query()->where('participant_id', $user->id)->sole();

    livewire(Chat::class, ['conversation' => $conversation->id])
        ->assertSee('Two orders are waiting for a call');
});
```

`Assistant::fake([...])` comes from laravel/ai's `Promptable` trait: each entry is one answer, in order.

## Driving a tool

```php
use App\Mcp\Servers\AcmeServer;
use App\Mcp\Tools\SearchOrders;

it('finds orders by number', function () {
    actingAs($user);

    AcmeServer::tool(SearchOrders::class, ['query' => 'RO-00012'])
        ->assertOk()
        ->assertSee('RO-00012');
});

it('refuses a tool the role does not allow', function () {
    actingAs($viewer);

    AcmeServer::tool(ConfirmOrder::class, ['id' => 1])
        ->assertHasErrors()
        ->assertSee('not found');   // a tool the role may not use is not registered on the server

    $direct = app(ConfirmOrder::class)->handle(new \Laravel\Mcp\Request(['id' => 1]));
    expect($direct->isError())->toBeTrue()
        ->and((string) $direct->content())->toContain('not allowed');
});
```

`Server::tool()` is laravel/mcp's testing helper: it runs the tool through the server, so the ability check applies exactly as in production.

## The chat's tool list

```php
use Laravel\Ai\Tools\McpServerTool;
use Packstub\Agents\Ai\ApprovableTool;
use Packstub\Agents\Facades\Agents;

it('wraps writes for approval', function () {
    actingAs($user);

    $tools = collect((new Assistant)->tools())->keyBy(fn ($tool) => $tool->name());

    expect($tools->get('search-orders'))->toBeInstanceOf(McpServerTool::class)->not->toBeInstanceOf(ApprovableTool::class)
        ->and($tools->get('confirm-order'))->toBeInstanceOf(ApprovableTool::class);
});
```

## The MCP endpoint

```php
use function Pest\Laravel\postJson;

it('serves MCP with a read or write token', function () {
    $mcp = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];
    $read = $user->createToken('laptop', ['read'])->plainTextToken;
    $write = $user->createToken('desk', ['read', 'write'])->plainTextToken;

    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 0, 'method' => 'tools/list'], $mcp)
        ->assertStatus(401);

    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer '.$read] + $mcp)
        ->assertOk()
        ->assertJsonPath('result.tools.0.name', 'search-orders');

    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'confirm-order', 'arguments' => ['id' => 1]]], ['Authorization' => 'Bearer '.$read] + $mcp)
        ->assertOk()
        ->assertJsonPath('result.isError', true);   // a read token cannot write

    auth()->forgetGuards();   // each MCP request is its own request in production; the test kernel keeps the resolved guard

    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'confirm-order', 'arguments' => ['id' => 1]]], ['Authorization' => 'Bearer '.$write] + $mcp)
        ->assertOk()
        ->assertJsonPath('result.isError', false);
});
```

## Budgets

```php
use Packstub\Agents\Models\AgentLimit;
use Packstub\Agents\Support\AgentBudget;
use Packstub\Agents\Support\AgentLimits;

it('stops a turn when the daily limit is spent', function () {
    AgentLimit::query()->create(['scope' => 'global', 'turns_per_day' => 1]);
    AgentLimits::flush();

    // … one assistant message exists …

    expect(AgentBudget::refusal('hello'))->toContain("today's limit");
});
```

## The package's own suite

```bash
composer test
```

The suite runs on Orchestra Testbench with an in-memory SQLite database and a fixture panel that has a `Widget` resource, two tools, an agent and a server, and covers tools and authorization, the chat, tables and charts, tokens and limits.
