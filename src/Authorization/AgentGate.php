<?php

namespace Packstub\Agents\Authorization;

use Illuminate\Http\Request;
use Laravel\Mcp\Server\Tool;
use Packstub\Agents\Audit\RecordAuditEntry;
use Packstub\Agents\Enums\AuditDecision;
use Packstub\Agents\Enums\ToolMode;
use Packstub\Agents\Models\AgentToken;
use Packstub\Agents\Support\ToolRegistry;

class AgentGate
{
    public function __construct(protected RecordAuditEntry $recordAuditEntry) {}

    /**
     * The token authenticated by the AuthenticateAgentToken middleware for the
     * current request, if any.
     */
    public function currentToken(): ?AgentToken
    {
        $token = request()->attributes->get('agentToken');

        return $token instanceof AgentToken ? $token : null;
    }

    /**
     * Decide whether the token may invoke the tool at the required mode.
     * Every decision — allow and deny alike — lands in the audit trail.
     */
    public function authorize(AgentToken $agentToken, string $toolName, ToolMode $required, array $arguments = []): bool
    {
        $allowed = $agentToken->isUsable() && $agentToken->allows($toolName, $required);

        $this->recordAuditEntry->handle(
            $agentToken,
            $toolName,
            $required,
            $allowed ? AuditDecision::Allowed : AuditDecision::Denied,
            $arguments,
            $allowed ? null : $this->denialSummary($agentToken, $toolName, $required),
        );

        return $allowed;
    }

    /**
     * Visibility for tools/list: any-mode grant on a usable token. Plain list
     * filtering is not audited — only invocation attempts are.
     */
    public function visible(AgentToken $agentToken, string $toolName): bool
    {
        return $agentToken->isUsable() && $agentToken->grantFor($toolName) !== null;
    }

    /**
     * shouldRegister() also gates tools/call in laravel/mcp: a direct call to
     * a hidden tool short-circuits as "Tool not found" before handle() runs.
     * When the current request body is exactly such a probe, record the denial
     * so ungranted invocation attempts still reach the audit trail.
     */
    public function auditProbeDenial(AgentToken $agentToken, Tool $tool, Request $request): void
    {
        if ($request->input('method') !== 'tools/call') {
            return;
        }

        if ($request->input('params.name') !== $tool->name()) {
            return;
        }

        $arguments = $request->input('params.arguments', []);

        $this->recordAuditEntry->handle(
            $agentToken,
            $tool->name(),
            ToolRegistry::requiredMode($tool->name()) ?? ToolMode::Read,
            AuditDecision::Denied,
            is_array($arguments) ? $arguments : [],
            $this->denialSummary($agentToken, $tool->name(), null),
        );
    }

    protected function denialSummary(AgentToken $agentToken, string $toolName, ?ToolMode $required): string
    {
        if (! $agentToken->isUsable()) {
            return 'Agent token is revoked or expired.';
        }

        $grant = $agentToken->grantFor($toolName);

        if ($grant === null) {
            return "No grant for tool [{$toolName}].";
        }

        return sprintf(
            'Grant [%s] does not cover required mode [%s].',
            $grant->value,
            $required?->value ?? 'unknown',
        );
    }
}
