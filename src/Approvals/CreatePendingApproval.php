<?php

namespace Packstub\Agents\Approvals;

use Packstub\Agents\Audit\RecordAuditEntry;
use Packstub\Agents\Enums\ApprovalStatus;
use Packstub\Agents\Enums\AuditDecision;
use Packstub\Agents\Enums\ToolMode;
use Packstub\Agents\Models\AgentToken;
use Packstub\Agents\Models\PendingApproval;
use Packstub\Agents\Support\ToolRegistry;

class CreatePendingApproval
{
    public function __construct(protected RecordAuditEntry $recordAuditEntry) {}

    public function handle(AgentToken $agentToken, string $toolName, Proposal $proposal): PendingApproval
    {
        $approval = PendingApproval::query()->create([
            'agent_token_id' => $agentToken->id,
            'tool' => $toolName,
            'arguments' => $proposal->arguments,
            'proposed_changes' => $proposal->proposedChanges,
            'status' => ApprovalStatus::Pending,
        ]);

        $this->recordAuditEntry->handle(
            $agentToken,
            $toolName,
            ToolRegistry::requiredMode($toolName) ?? ToolMode::Write,
            AuditDecision::PendingApproval,
            $proposal->arguments,
            $proposal->summary,
        );

        return $approval;
    }
}
