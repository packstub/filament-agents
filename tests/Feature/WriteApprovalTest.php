<?php

namespace Packstub\Agents\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packstub\Agents\Enums\ApprovalStatus;
use Packstub\Agents\Enums\AuditDecision;
use Packstub\Agents\Mcp\AgentServer;
use Packstub\Agents\Models\AgentAuditEntry;
use Packstub\Agents\Models\PendingApproval;
use Packstub\Agents\Tests\Fixtures\Models\Widget;
use Packstub\Agents\Tests\Fixtures\Tools\DeleteWidget;
use Packstub\Agents\Tests\Fixtures\Tools\UpdateWidget;
use Packstub\Agents\Tests\TestCase;

class WriteApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_write_tool_creates_a_pending_approval_with_a_field_level_diff(): void
    {
        $widget = Widget::query()->create(['name' => 'Old Name', 'description' => 'Same description']);

        $this->actAsAgent($this->makeAgentToken(['update-widget' => 'write']));

        AgentServer::tool(UpdateWidget::class, [
            'id' => $widget->id,
            'name' => 'New Name',
            'description' => 'Same description',
        ])->assertOk();

        $approval = PendingApproval::query()->sole();

        $this->assertSame('update-widget', $approval->tool);
        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertSame(
            ['name' => ['from' => 'Old Name', 'to' => 'New Name']],
            $approval->proposed_changes,
        );
    }

    public function test_a_write_tool_does_not_mutate_the_model(): void
    {
        $widget = Widget::query()->create(['name' => 'Old Name']);

        $this->actAsAgent($this->makeAgentToken(['update-widget' => 'write']));

        AgentServer::tool(UpdateWidget::class, ['id' => $widget->id, 'name' => 'New Name'])->assertOk();

        $this->assertSame('Old Name', $widget->fresh()->name);
    }

    public function test_a_no_op_update_creates_no_approval(): void
    {
        $widget = Widget::query()->create(['name' => 'Old Name']);

        $this->actAsAgent($this->makeAgentToken(['update-widget' => 'write']));

        AgentServer::tool(UpdateWidget::class, ['id' => $widget->id, 'name' => 'Old Name'])
            ->assertOk()
            ->assertSee('No changes detected');

        $this->assertSame(0, PendingApproval::query()->count());
    }

    public function test_write_tool_calls_are_audited_as_pending_approval(): void
    {
        $widget = Widget::query()->create(['name' => 'Old Name']);

        $this->actAsAgent($this->makeAgentToken(['update-widget' => 'write']));

        AgentServer::tool(UpdateWidget::class, ['id' => $widget->id, 'name' => 'New Name'])->assertOk();

        $this->assertTrue(
            AgentAuditEntry::query()
                ->where('tool', 'update-widget')
                ->where('decision', AuditDecision::PendingApproval->value)
                ->exists(),
        );
    }

    public function test_a_destructive_tool_creates_an_approval_without_executing(): void
    {
        $widget = Widget::query()->create(['name' => 'Doomed Widget']);

        $this->actAsAgent($this->makeAgentToken(['delete-widget' => 'destructive']));

        AgentServer::tool(DeleteWidget::class, ['id' => $widget->id])->assertOk();

        $this->assertNotNull($widget->fresh());
        $this->assertSame(1, PendingApproval::query()->where('tool', 'delete-widget')->count());
    }
}
