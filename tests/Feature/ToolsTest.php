<?php

use Laravel\Ai\Tools\McpServerTool;
use Laravel\Mcp\Request;
use Packstub\Agents\Ai\ApprovableTool;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Mcp\Tools\DrawChart;
use Packstub\Agents\Mcp\Tools\ShowTable;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Models\Widget;
use Packstub\Agents\Tests\Fixtures\Tools\ListWidgets;
use Packstub\Agents\Tests\Fixtures\Tools\RenameWidget;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;
use Packstub\Agents\Tests\Fixtures\WidgetServer;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

it('reads the tool list from the server class and wraps writes for approval', function () {
    actingAs($this->user());

    expect(Agents::toolClasses())->toBe([ListWidgets::class, RenameWidget::class, ShowTable::class, DrawChart::class])
        ->and(Agents::agentClass())->toBe(WidgetAgent::class)
        ->and(Agents::name())->toBe('Ask Widgets');

    $tools = collect((new WidgetAgent)->tools())->keyBy(fn ($t) => $t->name());

    expect($tools->get('list-widgets'))->toBeInstanceOf(McpServerTool::class)->not->toBeInstanceOf(ApprovableTool::class)
        ->and($tools->get('rename-widget'))->toBeInstanceOf(ApprovableTool::class)
        ->and($tools->get('show-table'))->not->toBeInstanceOf(ApprovableTool::class)
        ->and($tools)->toHaveCount(4);
});

it('gives each person only the tools their abilities allow, in the list and on a direct call', function () {
    actingAs($this->user());
    $this->widgets();
    Abilities::$allowed = ['widgets.view'];
    Abilities::$role = 'Viewer';

    WidgetServer::tool(ListWidgets::class, [])->assertOk()->assertSee('Alpha');
    WidgetServer::tool(RenameWidget::class, ['id' => 1, 'name' => 'X'])->assertHasErrors()->assertSee('not found');

    $direct = app(RenameWidget::class)->handle(new Request(['id' => 1, 'name' => 'X']));
    expect($direct->isError())->toBeTrue()->and((string) $direct->content())->toContain('Your role (Viewer) is not allowed');

    $names = collect((new WidgetAgent)->tools())->map(fn ($t) => $t->name())->all();
    expect($names)->toContain('list-widgets', 'show-table')->not->toContain('rename-widget');

    Abilities::$role = null;
    $direct = app(RenameWidget::class)->handle(new Request(['id' => 1, 'name' => 'X']));
    expect((string) $direct->content())->toContain('You are not allowed');
});

it('hands domain errors back to the model as tool errors', function () {
    actingAs($this->user());

    $response = app(RenameWidget::class)->handle(new Request(['id' => 99, 'name' => 'X']));

    expect($response->isError())->toBeTrue()->and((string) $response->content())->toContain('No widget matches 99');
});

it('serves MCP over HTTP with a read or write token', function () {
    $user = $this->user();
    $this->widgets();
    $read = $user->createToken('laptop', ['read'])->plainTextToken;
    $write = $user->createToken('desk', ['read', 'write'])->plainTextToken;
    $headers = fn (string $token) => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json, text/event-stream'];

    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 0, 'method' => 'tools/list'], ['Accept' => 'application/json'])->assertStatus(401);

    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $headers($read))
        ->assertOk()
        ->assertJsonPath('result.tools.0.name', 'list-widgets');

    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'list-widgets', 'arguments' => ['limit' => 1]]], $headers($read))
        ->assertOk()
        ->assertJsonPath('result.isError', false);

    // A read token cannot write, even for a person whose role could.
    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'rename-widget', 'arguments' => ['id' => 1, 'name' => 'Renamed']]], $headers($read))
        ->assertOk()
        ->assertJsonPath('result.isError', true);

    // Each MCP request is its own request in production; the test kernel keeps the resolved guard, so reset it.
    auth()->forgetGuards();

    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'rename-widget', 'arguments' => ['id' => 1, 'name' => 'Renamed']]], $headers($write))
        ->assertOk()
        ->assertJsonPath('result.isError', false);

    expect(Widget::query()->find(1)->name)->toBe('Renamed');
});

it('builds the prompt from the persona, the domain, the generic rules and the live context', function () {
    actingAs($this->user(['name' => 'Grace Hopper']));
    $this->widgets();

    $agent = new WidgetAgent(modelKey: 'deep');
    $static = $agent->staticInstructions();
    $dynamic = $agent->dynamicInstructions();

    expect($static)->toStartWith('You are Ask Widgets')
        ->toContain('## What the workspace is', 'draft, live, retired', '## How to work', 'show_table', '## How to answer')
        ->and($dynamic)->toContain('## Now', 'Grace Hopper', 'role Owner', 'Answer language: English', 'Widgets in the catalogue: 3.')
        ->and($agent->instructions())->toBe($static."\n\n".$dynamic)
        ->and($agent->maxSteps())->toBe(12)
        ->and($agent->providerOptions('anthropic'))->toHaveKeys(['system', 'output_config'])
        ->and($agent->providerOptions('anthropic')['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and(WidgetAgent::supportsReasoning('gpt-5.2'))->toBeTrue()
        ->and(WidgetAgent::supportsReasoning('gpt-4o'))->toBeFalse();
});
