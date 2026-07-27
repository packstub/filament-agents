<?php

namespace Packstub\Agents\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;

class AgentServer extends Server
{
    protected string $name = 'Packstub Agents';

    protected string $instructions = <<<'MARKDOWN'
        This MCP server exposes a governed set of tools over this Laravel
        application. Governance rules:

        - You only see tools your agent token has been granted. Calling any
          other tool fails.
        - Read tools return data directly.
        - Write and destructive tools NEVER change anything immediately. They
          return a proposed change (a field-level diff) and file a pending
          approval; a human administrator reviews and applies or rejects it in
          the control plane. Report the approval id back to the user so they
          can follow up.
        - Every call — allowed or denied — is written to an audit trail.
        MARKDOWN;

    public function __construct(Transport $transport)
    {
        parent::__construct($transport);

        $this->tools = config('packstub-agents.tools', []);
    }
}
