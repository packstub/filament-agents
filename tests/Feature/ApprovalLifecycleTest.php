<?php

namespace Packstub\Agents\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packstub\Agents\Approvals\ApplyPendingApproval;
use Packstub\Agents\Approvals\RejectPendingApproval;
use Packstub\Agents\Enums\ApprovalStatus;
use Packstub\Agents\Enums\AuditDecision;
use Packstub\Agents\Mcp\AgentServer;
use Packstub\Agents\Models\AgentAuditEntry;
use Packstub\Agents\Models\AgentToken;
use Packstub\Agents\Models\PendingApproval;
use Packstub\Agents\Tests\Fixtures\Models\User;
use Packstub\Agents\Tests\Fixtures\Models\Widget;
use Packstub\Agents\Tests\Fixtures\Tools\DeleteWidget;
use Packstub\Agents\Tests\Fixtures\Tools\UpdateWidget;
use Packstub\Agents\Tests\TestCase;

class ApprovalLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_approving_applies_the_change_records_previous_values_and_audits(): void
    {
        [$widget, $approval] = $this->makeUpdateProposal();

        $approval = app(ApplyPendingApproval::class)->handle($approval, $this->makeAdmin());

        $this->assertSame(ApprovalStatus::Applied, $approval->status);
        $this->assertSame('New Name', $widget->fresh()->name);
        $this->assertSame(['name' => 'Old Name'], $approval->result['previous']);
        $this->assertNotNull($approval->decided_at);
        $this->assertNotNull($approval->applied_at);

        $this->assertTrue(AgentAuditEntry::query()->where('decision', AuditDecision::Approved->value)->exists());
        $this->assertTrue(AgentAuditEntry::query()->where('decision', AuditDecision::Applied->value)->exists());
    }

    public function test_approving_with_a_revoked_token_fails_and_does_not_mutate(): void
    {
        [$widget, $approval, $agentToken] = $this->makeUpdateProposal();

        $agentToken->forceFill(['revoked_at' => now()])->save();

        $approval = app(ApplyPendingApproval::class)->handle($approval, $this->makeAdmin());

        $this->assertSame(ApprovalStatus::Failed, $approval->status);
        $this->assertSame('Old Name', $widget->fresh()->name);
        $this->assertNull($approval->applied_at);
        $this->assertTrue(
            AgentAuditEntry::query()
                ->where('decision', AuditDecision::Denied->value)
                ->where('summary', 'like', '%no longer valid%')
                ->exists(),
        );
    }

    public function test_approving_with_a_downgraded_grant_fails_and_does_not_mutate(): void
    {
        [$widget, $approval, $agentToken] = $this->makeUpdateProposal();

        $agentToken->forceFill(['grants' => ['update-widget' => 'read']])->save();

        $approval = app(ApplyPendingApproval::class)->handle($approval, $this->makeAdmin());

        $this->assertSame(ApprovalStatus::Failed, $approval->status);
        $this->assertSame('Old Name', $widget->fresh()->name);
    }

    public function test_rejecting_leaves_data_untouched_and_audits(): void
    {
        [$widget, $approval] = $this->makeUpdateProposal();

        $approval = app(RejectPendingApproval::class)->handle($approval, $this->makeAdmin());

        $this->assertSame(ApprovalStatus::Rejected, $approval->status);
        $this->assertSame('Old Name', $widget->fresh()->name);
        $this->assertTrue(AgentAuditEntry::query()->where('decision', AuditDecision::Rejected->value)->exists());
    }

    public function test_approving_a_destructive_proposal_executes_it_and_records_previous_values(): void
    {
        $widget = Widget::query()->create(['name' => 'Doomed Widget']);

        $agentToken = $this->makeAgentToken(['delete-widget' => 'destructive']);
        $this->actAsAgent($agentToken);

        AgentServer::tool(DeleteWidget::class, ['id' => $widget->id])->assertOk();

        $approval = app(ApplyPendingApproval::class)->handle(
            PendingApproval::query()->sole(),
            $this->makeAdmin(),
        );

        $this->assertSame(ApprovalStatus::Applied, $approval->status);
        $this->assertNull($widget->fresh());
        $this->assertSame('Doomed Widget', $approval->result['previous']['name']);
    }

    /**
     * @return array{0: Widget, 1: PendingApproval, 2: AgentToken}
     */
    private function makeUpdateProposal(): array
    {
        $widget = Widget::query()->create(['name' => 'Old Name']);

        $agentToken = $this->makeAgentToken(['update-widget' => 'write']);
        $this->actAsAgent($agentToken);

        AgentServer::tool(UpdateWidget::class, ['id' => $widget->id, 'name' => 'New Name'])->assertOk();

        return [$widget, PendingApproval::query()->sole(), $agentToken];
    }

    private function makeAdmin(): User
    {
        return User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('password'),
        ]);
    }
}
