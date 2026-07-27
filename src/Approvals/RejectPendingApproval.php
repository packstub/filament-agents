<?php

namespace Packstub\Agents\Approvals;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Packstub\Agents\Audit\RecordAuditEntry;
use Packstub\Agents\Enums\ApprovalStatus;
use Packstub\Agents\Enums\AuditDecision;
use Packstub\Agents\Enums\ToolMode;
use Packstub\Agents\Models\PendingApproval;
use Packstub\Agents\Support\ToolRegistry;

class RejectPendingApproval
{
    public function __construct(protected RecordAuditEntry $recordAuditEntry) {}

    public function handle(PendingApproval $approval, Authenticatable $decidedBy): PendingApproval
    {
        if (! $approval->isPending()) {
            throw new InvalidArgumentException('Only pending approvals can be rejected.');
        }

        $approval->forceFill([
            'status' => ApprovalStatus::Rejected,
            'decided_by' => $decidedBy->getAuthIdentifier(),
            'decided_at' => now(),
        ])->save();

        $this->recordAuditEntry->handle(
            $approval->agentToken,
            $approval->tool,
            ToolRegistry::requiredMode($approval->tool) ?? ToolMode::Write,
            AuditDecision::Rejected,
            $approval->arguments ?? [],
            sprintf('Approval #%d rejected.', $approval->id),
        );

        return $approval;
    }
}
