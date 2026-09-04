<?php

use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Mcp\Request;
use Packstub\Agents\Filament\Pages\Chat;
use Packstub\Agents\Filters\Filter;
use Packstub\Agents\Livewire\AgentTable;
use Packstub\Agents\Mcp\Tools\DrawChart;
use Packstub\Agents\Mcp\Tools\ShowTable;
use Packstub\Agents\Support\AgentResources;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Filament\Resources\Widgets\WidgetResource;
use Packstub\Agents\Tests\Fixtures\Models\Widget;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

use function Pest\Laravel\actingAs;
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
    ConversationMessage::query()->create([
        'id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'agent' => WidgetAgent::class, 'role' => 'assistant', 'content' => 'Two widgets are live.', 'attachments' => [], 'usage' => [], 'meta' => [],
        'tool_calls' => [['id' => 'call_t', 'name' => 'show-table', 'arguments' => ['table' => 'widgets']]],
        'tool_results' => [['id' => 'call_t', 'name' => 'show-table', 'result' => json_encode($payload)]],
    ]);

    livewire(Chat::class, ['conversation' => $conversation->id])
        ->assertSee('Two widgets are live.')
        ->assertSee('Live ones')
        ->assertSee('Alpha')
        ->assertDontSee('Beta');

    livewire(AgentTable::class, ['resource' => 'widgets', 'filters' => ['live_only' => true], 'title' => 'Live ones'])
        ->assertCanSeeTableRecords([$alpha])
        ->assertTableActionExists('edit');

    expect(Chat::tableFromResult(json_encode(['table' => ['resource' => 'nope']])))->toBeNull();
});

it('validates drawn charts and renders a chart from a stored tool result', function () {
    $user = $this->user();
    actingAs($user);

    $bad = app(DrawChart::class)->handle(new Request(['title' => 'Stock', 'labels' => ['A', 'B'], 'datasets' => [['label' => 'On hand', 'data' => [3, 5, 9]]]]));
    expect($bad->isError())->toBeTrue();

    $payload = json_decode((string) app(DrawChart::class)->handle(new Request(['title' => 'Prices', 'type' => 'line', 'labels' => ['Alpha', 'Beta'], 'datasets' => [['label' => 'Price', 'data' => [10, 20]]]]))->content(), true);
    $chart = Chat::chartFromResult($payload);
    expect($chart['type'])->toBe('line')->and($chart['data']['labels'])->toBe(['Alpha', 'Beta'])->and($chart['data']['datasets'][0]['fill'])->toBeTrue();

    $conversation = Conversation::query()->create(['id' => (string) Str::uuid(), 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id, 'title' => 'Prices']);
    ConversationMessage::query()->create([
        'id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id,
        'agent' => WidgetAgent::class, 'role' => 'assistant', 'content' => 'Here are the prices.', 'attachments' => [], 'usage' => [], 'meta' => [],
        'tool_calls' => [['id' => 'call_1', 'name' => 'draw-chart', 'arguments' => ['title' => 'Prices']]],
        'tool_results' => [['id' => 'call_1', 'name' => 'draw-chart', 'result' => json_encode($payload)]],
    ]);

    livewire(Chat::class, ['conversation' => $conversation->id])
        ->assertSee('Here are the prices.')
        ->assertSeeHtml('x-ref="canvas"')
        ->assertSee('Prices');
});
