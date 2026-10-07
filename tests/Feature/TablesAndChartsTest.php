<?php

use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Mcp\Request;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Packstub\Agents\AgentsPlugin;
use Packstub\Agents\Filament\Pages\Chat;
use Packstub\Agents\Filament\Pages\ResourceTable;
use Packstub\Agents\Filters\Filter;
use Packstub\Agents\Livewire\AgentTable;
use Packstub\Agents\Mcp\Tools\DrawChart;
use Packstub\Agents\Mcp\Tools\ShowTable;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentResources;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Filament\Resources\Widgets\WidgetResource;
use Packstub\Agents\Tests\Fixtures\Models\Widget;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

it('discovers the panel resources that implement AgentResource and normalizes their filters', function () {
    actingAs($this->user());

    expect(AgentResources::all())->toBe(['widgets' => WidgetResource::class])
        ->and(AgentResources::forModel(Widget::class))->toBe(WidgetResource::class)
        ->and(array_keys(AgentResources::filters('widgets')))->toBe(['query', 'status', 'live_only', 'min_price', 'created_from']);

    expect(AgentResources::normalizeFilters('widgets', [
        'query' => '  al ', 'status' => 'live', 'live_only' => 'false', 'min_price' => '15', 'created_from' => '', 'bogus' => 1,
    ]))->toBe(['query' => 'al', 'status' => ['live'], 'min_price' => 15]);

    expect(AgentResources::normalizeFilters('widgets', ['live_only' => true, 'status' => ['draft', '']]))->toBe(['status' => ['draft'], 'live_only' => true]);

    $enum = Filter::enum('status', ['a', 'b']);
    expect($enum->values())->toBe(['a', 'b'])->and($enum->normalize(' a '))->toBe('a')->and($enum->hint())->toBe('a|b');
});

it('applies the filters to a query the same way for search tools and the embedded table', function () {
    actingAs($this->user());
    $this->widgets();

    $rows = fn (array $f) => AgentResources::apply('widgets', Widget::query(), AgentResources::normalizeFilters('widgets', $f))->pluck('name')->all();

    expect($rows(['live_only' => true]))->toBe(['Alpha', 'Gamma'])
        ->and($rows(['status' => ['draft']]))->toBe(['Beta'])
        ->and($rows(['min_price' => 20]))->toBe(['Beta', 'Gamma'])
        ->and($rows(['query' => 'amm']))->toBe(['Gamma'])
        ->and($rows(['created_from' => now()->addDay()->toDateString()]))->toBe([]);
});

it('generates the show-table schema from the resources and refuses tables the person cannot see', function () {
    actingAs($this->user());
    $this->widgets();

    $tool = app(ShowTable::class);
    $schema = $tool->toArray()['inputSchema'];

    expect($tool->description())->toContain('widgets (widgets)')
        ->and($schema['properties'])->toHaveKeys(['table', 'title', 'filters'])
        ->and($schema['properties']['table']['enum'])->toBe(['widgets'])
        ->and(json_encode($schema['properties']['filters']))->toContain('"status"', 'widgets: draft|live|retired', '"live_only"');

    $payload = json_decode((string) $tool->handle(new Request(['table' => 'widgets', 'title' => 'Live ones', 'filters' => ['live_only' => true, 'bogus' => 'x']]))->content(), true);
    expect($payload['total'])->toBe(2)
        ->and($payload['table'])->toBe(['resource' => 'widgets', 'filters' => ['live_only' => true], 'title' => 'Live ones']);

    Abilities::$allowed = ['nothing'];
    expect(app(ShowTable::class)->handle(new Request(['table' => 'widgets']))->isError())->toBeTrue();
    expect(app(ShowTable::class)->handle(new Request(['table' => 'nope']))->isError())->toBeTrue();
});

it('embeds a live resource table in the chat with the resource\'s own actions', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();

    $payload = json_decode((string) app(ShowTable::class)->handle(new Request(['table' => 'widgets', 'title' => 'Live ones', 'filters' => ['live_only' => true]]))->content(), true);

    $conversation = Conversation::query()->create(['id' => (string) Str::uuid(), 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id, 'title' => 'Live ones']);
    ConversationMessage::query()->create(legacyRow([
        'id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'agent' => WidgetAgent::class, 'role' => 'assistant', 'content' => 'Two widgets are live.', 'attachments' => [], 'usage' => [], 'meta' => [],
        'tool_calls' => [['id' => 'call_t', 'name' => 'show-table', 'arguments' => ['table' => 'widgets']]],
        'tool_results' => [['id' => 'call_t', 'name' => 'show-table', 'result' => json_encode($payload)]],
    ]));

    livewire(Chat::class, ['conversation' => $conversation->id])
        ->assertSee('Two widgets are live.')
        ->assertSee('Live ones')
        ->assertSee('Alpha')
        ->assertDontSee('Beta');

    // The assistant chose the filters and the answer says what the table shows: the search box and the filter
    // button start hidden; AgentsPlugin::embeddedTable() brings the resource's own back.
    livewire(AgentTable::class, ['resource' => 'widgets', 'filters' => ['live_only' => true], 'title' => 'Live ones'])
        ->assertCanSeeTableRecords([$alpha])
        ->assertTableActionExists('edit')
        ->loadTable()
        ->assertDontSeeHtml('fi-ta-search-field')
        ->assertDontSeeHtml('fi-ta-filters-dropdown');

    AgentsPlugin::current()->embeddedTable(search: true, filters: true);
    livewire(AgentTable::class, ['resource' => 'widgets', 'filters' => ['live_only' => true], 'title' => 'Live ones'])
        ->loadTable()
        ->assertSeeHtml('fi-ta-search-field')
        ->assertSeeHtml('fi-ta-filters-dropdown');
    AgentsPlugin::current()->embeddedTable();

    expect(AgentChat::tableFromResult(json_encode(['table' => ['resource' => 'nope']])))->toBeNull();
});

it('shows a short result whole and compact: the caption and the row count as its header, no pager, search or bulk actions', function () {
    actingAs($this->user());
    [$alpha, , $gamma] = $this->widgets();

    $table = livewire(AgentTable::class, ['resource' => 'widgets', 'filters' => ['live_only' => true], 'title' => 'Live ones'])
        ->loadTable()
        ->assertCanSeeTableRecords([$alpha, $gamma])
        ->assertCountTableRecords(2)
        ->assertSee('Live ones')
        ->assertSee('2 rows')
        ->assertSeeHtml('fi-chat-table-compact')
        ->assertDontSeeHtml('fi-pagination')
        ->assertDontSeeHtml('fi-ta-search-field')
        ->assertDontSee('Open all');

    expect($table->instance()->fullUrl())->toBeNull()
        ->and($table->instance()->total())->toBe(2)
        ->and($table->instance()->limit())->toBe(AgentTable::DEFAULT_LIMIT);

    // Without a caption the header names the resource; one row and none read as such.
    livewire(AgentTable::class, ['resource' => 'widgets', 'filters' => ['status' => ['draft']]])->loadTable()->assertSee('Widgets')->assertSee('1 row');
    livewire(AgentTable::class, ['resource' => 'widgets', 'filters' => ['min_price' => 500]])->loadTable()->assertSee('No rows');

    // The tool tells the model the same: every row is there.
    $note = json_decode((string) app(ShowTable::class)->handle(new Request(['table' => 'widgets', 'filters' => ['live_only' => true]]))->content(), true)['note'];
    expect($note)->toContain('A table with these 2 rows is rendered')->not->toContain('first');
});

it('shows the first rows of a long result with a link to the full table, where the list page\'s search, filters and pagination are', function () {
    actingAs($this->user());
    $widgets = collect(range(1, 30))->map(fn (int $n) => Widget::query()->create(['name' => 'Widget '.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'status' => 'live', 'price' => $n]));
    Widget::query()->create(['name' => 'A draft', 'status' => 'draft', 'price' => 1]);

    // In the answer: the first 25, the count, and a link that carries the assistant's filters and the caption.
    $table = livewire(AgentTable::class, ['resource' => 'widgets', 'filters' => ['live_only' => true], 'title' => 'Live ones'])
        ->loadTable()
        ->assertCanSeeTableRecords($widgets->take(25))
        ->assertCanNotSeeTableRecords($widgets->skip(25))
        ->assertSee('The first 25 of 30')
        ->assertSee('Open all 30 in a full table')
        ->assertDontSeeHtml('fi-pagination');

    $url = $table->instance()->fullUrl();
    expect($table->instance()->getTableRecords())->toHaveCount(25);
    expect($url)->toBe(ResourceTable::getUrl(['resource' => 'widgets', 'filters' => ['live_only' => true], 'title' => 'Live ones']))
        ->and($url)->toContain('/agent-table/widgets', 'filters%5Blive_only%5D=1', 'title=Live%20ones')
        ->and(json_decode((string) app(ShowTable::class)->handle(new Request(['table' => 'widgets', 'filters' => ['live_only' => true]]))->content(), true)['note'])
        ->toContain('the first 25 of these 30 rows', 'a link that opens all of them');

    // The cap is the plugin's.
    AgentsPlugin::current()->embeddedTable(limit: 5);
    livewire(AgentTable::class, ['resource' => 'widgets', 'filters' => ['live_only' => true]])
        ->loadTable()
        ->assertCanSeeTableRecords($widgets->take(5))
        ->assertCanNotSeeTableRecords($widgets->skip(5))
        ->assertSee('The first 5 of 30')
        ->assertSee('Open all 30 in a full table');
    AgentsPlugin::current()->embeddedTable();

    // The page behind the link: the same rows, the caption as its title, the filters normalized again on the way in.
    get($url)->assertOk()->assertSee('Live ones')->assertSee('All widgets');

    Livewire::withQueryParams(['filters' => ['live_only' => '1', 'bogus' => 'x'], 'title' => 'Live ones'])
        ->test(ResourceTable::class, ['resource' => 'widgets'])
        ->assertSet('filters', ['live_only' => true])
        ->assertSet('caption', 'Live ones');

    // There the table is the resource's own, with its search box, its filters and a pager, ten rows a page.
    livewire(AgentTable::class, ['resource' => 'widgets', 'filters' => ['live_only' => true], 'full' => true])
        ->loadTable()
        ->assertCountTableRecords(30)
        ->assertCanSeeTableRecords($widgets->take(10))
        ->assertCanNotSeeTableRecords($widgets->skip(10))
        ->assertSeeHtml('fi-pagination')
        ->assertSeeHtml('fi-ta-search-field')
        ->assertSeeHtml('fi-ta-filters-dropdown')
        ->assertDontSeeHtml('fi-chat-table-compact')
        ->assertDontSee('Open all')
        ->searchTable('Widget 07')
        ->assertCountTableRecords(1);

    // A table that does not exist is not found; one the person may not see is refused.
    get(ResourceTable::getUrl(['resource' => 'nope']))->assertNotFound();
    Abilities::$allowed = ['nothing'];
    get(ResourceTable::getUrl(['resource' => 'widgets']))->assertForbidden();
});

it('refuses a Livewire request that changes what the embedded table shows: the resource, the filters, the caption and the full view are locked', function () {
    actingAs($this->user());
    [$alpha] = $this->widgets();

    $table = livewire(AgentTable::class, ['resource' => 'widgets', 'filters' => ['live_only' => true], 'title' => 'Live ones'])
        ->loadTable()
        ->assertCanSeeTableRecords([$alpha]);

    // A tampered filter value would skip normalizeFilters() and reach the resource's closures as sent.
    foreach (['resource' => 'orders', 'filters' => ['query' => ['x'], 'status' => 'live'], 'title' => 'Mine', 'full' => true] as $property => $value) {
        expect(fn () => $table->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class, $property);
    }

    $table->assertSet('resource', 'widgets')->assertSet('filters', ['live_only' => true])->assertSet('title', 'Live ones')->assertSet('full', false);

    // The ability is checked on every request, not only when the component is mounted.
    Abilities::$allowed = ['nothing'];
    $table->call('$refresh')->assertForbidden();
});

it('titles the full-table page without a resource, as Shield reads every page\'s title for its permissions', function () {
    $page = app(ResourceTable::class);

    expect($page->getTitle())->toBe('Table')
        ->and($page->getBreadcrumbs())->toBe(['Table', 'Table']);
});

it('validates drawn charts and renders a chart from a stored tool result', function () {
    $user = $this->user();
    actingAs($user);

    $bad = app(DrawChart::class)->handle(new Request(['title' => 'Stock', 'labels' => ['A', 'B'], 'datasets' => [['label' => 'On hand', 'data' => [3, 5, 9]]]]));
    expect($bad->isError())->toBeTrue();

    $payload = json_decode((string) app(DrawChart::class)->handle(new Request(['title' => 'Prices', 'type' => 'line', 'labels' => ['Alpha', 'Beta'], 'datasets' => [['label' => 'Price', 'data' => [10, 20]]]]))->content(), true);
    $chart = AgentChat::chartFromResult($payload);
    expect($chart['type'])->toBe('line')->and($chart['data']['labels'])->toBe(['Alpha', 'Beta'])->and($chart['data']['datasets'][0]['fill'])->toBeTrue();

    $conversation = Conversation::query()->create(['id' => (string) Str::uuid(), 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id, 'title' => 'Prices']);
    ConversationMessage::query()->create(legacyRow([
        'id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'agent' => WidgetAgent::class, 'role' => 'assistant', 'content' => 'Here are the prices.', 'attachments' => [], 'usage' => [], 'meta' => [],
        'tool_calls' => [['id' => 'call_1', 'name' => 'draw-chart', 'arguments' => ['title' => 'Prices']]],
        'tool_results' => [['id' => 'call_1', 'name' => 'draw-chart', 'result' => json_encode($payload)]],
    ]));

    livewire(Chat::class, ['conversation' => $conversation->id])
        ->assertSee('Here are the prices.')
        ->assertSeeHtml('x-ref="canvas"')
        ->assertSee('Prices');
});
