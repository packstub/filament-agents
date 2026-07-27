<?php

namespace Packstub\Agents\Approvals;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Packstub\Agents\Audit\RecordAuditEntry;
use Packstub\Agents\Enums\ApprovalStatus;
use Packstub\Agents\Enums\AuditDecision;
use Packstub\Agents\Enums\ToolMode;
use Packstub\Agents\Models\PendingApproval;
use Packstub\Agents\Support\ToolRegistry;
use Throwable;

class ApplyPendingApproval
{
    public function __construct(protected RecordAuditEntry $recordAuditEntry) {}

    public function handle(PendingApproval $approval, Authenticatable $decidedBy): PendingApproval
    {
        if (! $approval->isPending()) {
            throw new InvalidArgumentException('Only pending approvals can be applied.');
        }

        $agentToken = $approval->agentToken;
        $tool = ToolRegistry::resolve($approval->tool);
        $requiredMode = $tool === null ? null : $tool::requiredMode();

        $this->recordAuditEntry->handle(
            $agentToken,
            $approval->tool,
            $requiredMode ?? ToolMode::Write,
            AuditDecision::Approved,
            $approval->arguments ?? [],
            sprintf('Approval #%d approved.', $approval->id),
        );

        // The grant is re-validated at apply time: a token revoked, expired,
        // or downgraded between proposal and approval must not have its
        // pending work executed under the old authority.
        if (
            $tool === null
            || $agentToken === null
            || ! $agentToken->isUsable()
            || ! $agentToken->allows($approval->tool, $requiredMode)
        ) {
            $approval->forceFill([
                'status' => ApprovalStatus::Failed,
                'decided_by' => $decidedBy->getAuthIdentifier(),
                'decided_at' => now(),
                'result' => ['error' => 'The agent token grant is no longer valid at apply time.'],
            ])->save();

            $this->recordAuditEntry->handle(
                $agentToken,
                $approval->tool,
                $requiredMode ?? ToolMode::Write,
                AuditDecision::Denied,
                $approval->arguments ?? [],
                sprintf('Approval #%d not applied: grant no longer valid.', $approval->id),
            );

            return $approval;
        }

        try {
            DB::transaction(function () use ($approval, $tool, $decidedBy): void {
                $result = $tool->apply($approval);

                $approval->forceFill([
                    'status' => ApprovalStatus::Applied,
                    'decided_by' => $decidedBy->getAuthIdentifier(),
                    'decided_at' => now(),
                    'applied_at' => now(),
                    'result' => $result,
                ])->save();
            });
        } catch (Throwable $exception) {
            $approval->forceFill([
                'status' => ApprovalStatus::Failed,
                'decided_by' => $decidedBy->getAuthIdentifier(),
                'decided_at' => now(),
                'result' => ['error' => Str::limit($exception->getMessage(), 500)],
            ])->save();

            report($exception);

            return $approval;
        }

        $this->recordAuditEntry->handle(
            $agentToken,
            $approval->tool,
            $requiredMode,
            AuditDecision::Applied,
            $approval->arguments ?? [],
            sprintf('Approval #%d applied.', $approval->id),
        );

        return $approval;
    }
}
