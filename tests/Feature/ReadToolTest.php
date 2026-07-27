<?php

namespace Packstub\Agents\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packstub\Agents\Mcp\AgentServer;
use Packstub\Agents\Tests\Fixtures\Models\Widget;
use Packstub\Agents\Tests\Fixtures\Tools\ListWidgets;
use Packstub\Agents\Tests\TestCase;

class ReadToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_granted_read_tool_returns_data(): void
    {
        Widget::query()->create(['name' => 'Flux Capacitor', 'description' => 'Makes time travel possible']);

        $this->actAsAgent($this->makeAgentToken(['list-widgets' => 'read']));

        AgentServer::tool(ListWidgets::class)
            ->assertOk()
            ->assertSee('Flux Capacitor');
    }

    public function test_a_read_tool_without_a_grant_is_denied(): void
    {
        Widget::query()->create(['name' => 'Flux Capacitor']);

        $this->actAsAgent($this->makeAgentToken(['update-widget' => 'write']));

        AgentServer::tool(ListWidgets::class)->assertHasErrors();
    }
}
