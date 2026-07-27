<?php

namespace Packstub\Agents\Mcp;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Packstub\Agents\Approvals\CreatePendingApproval;
use Packstub\Agents\Approvals\Proposal;
use Packstub\Agents\Authorization\AgentGate;
use Packstub\Agents\Models\PendingApproval;

/**
 * A write or destructive tool. Invoking it NEVER mutates anything: it builds
 * a reviewable diff (the Proposal) and files a pending approval. The mutation
 * runs only when an admin approves it in the control plane, at which point
 * apply() executes inside a transaction with the grant re-validated.
 */
abstract class ApprovableTool extends GovernedTool
{
    /**
     * Build the change this invocation proposes, or null when the request is
     * a no-op. Must not mutate anything.
     */
    abstract protected function proposal(Request $request): ?Proposal;

    /**
     * Execute the approved change. Runs inside a DB transaction after an admin
     * approves. Return the result payload — include a `previous` key holding
     * the pre-change values so every applied change is undoable by hand.
     *
     * @return array<string, mixed>
     */
    abstract public function apply(PendingApproval $approval): array;

    public function handle(Request $request, AgentGate $gate, CreatePendingApproval $createPendingApproval): Response|ResponseFactory
    {
        $agentToken = $this->authorizeOrFail($gate, $request);

        $proposal = $this->proposal($request);

        if ($proposal === null || $proposal->isEmpty()) {
            return Response::text('No changes detected; nothing to approve.');
        }

        $approval = $createPendingApproval->handle($agentToken, $this->name(), $proposal);

        return Response::structured([
            'approval_id' => $approval->id,
            'status' => $approval->status->value,
            'proposed_changes' => $approval->proposed_changes,
            'message' => 'An admin must approve this change in the control plane before it is applied.',
        ]);
    }
}
