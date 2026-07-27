<?php

namespace Packstub\Agents\Mcp;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;
use Packstub\Agents\Authorization\AgentGate;
use Packstub\Agents\Enums\ToolMode;
use Packstub\Agents\Models\AgentToken;

abstract class GovernedTool extends Tool
{
    /**
     * The capability mode a grant must cover before this tool may be invoked.
     * Keep in sync with the tool's MCP annotation (#[IsReadOnly] /
     * #[IsDestructive]) — the annotation is a client-facing hint, this is the
     * enforced contract.
     */
    abstract public static function requiredMode(): ToolMode;

    /**
     * Hide the tool from any token without a grant for it. laravel/mcp applies
     * this filter to tools/call as well, so a hidden tool is also uninvokable;
     * auditProbeDenial() keeps such invocation attempts in the audit trail.
     */
    public function shouldRegister(AgentGate $gate, HttpRequest $request): bool
    {
        $agentToken = $gate->currentToken();

        if ($agentToken === null) {
            return false;
        }

        if ($gate->visible($agentToken, $this->name())) {
            return true;
        }

        $gate->auditProbeDenial($agentToken, $this, $request);

        return false;
    }

    /**
     * Authorize (and audit) the current invocation, or abort with an error the
     * MCP layer relays to the agent verbatim.
     *
     * @throws AuthorizationException
     */
    protected function authorizeOrFail(AgentGate $gate, Request $request): AgentToken
    {
        $agentToken = $gate->currentToken();

        if ($agentToken === null || ! $gate->authorize($agentToken, $this->name(), static::requiredMode(), $request->all())) {
            throw new AuthorizationException(sprintf(
                'This agent token is not granted [%s] access to the [%s] tool.',
                static::requiredMode()->value,
                $this->name(),
            ));
        }

        return $agentToken;
    }
}
