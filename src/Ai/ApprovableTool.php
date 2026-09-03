<?php

namespace Packstub\Agents\Ai;

use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\Request;

/**
 * A write tool as the chat sees it: the same laravel/mcp tool, but the agent
 * may only PROPOSE the call. laravel/ai pauses the turn, the panel shows what
 * would run (tool + arguments) with Approve / Reject, and only an approval
 * executes it — the human stays in the loop for every change.
 */
class ApprovableTool extends McpServerTool implements Approvable
{
    use InteractsWithApprovals;

    protected function needsApproval(Request $request): Approval|bool
    {
        return Approval::required($this->tool->title());
    }
}
