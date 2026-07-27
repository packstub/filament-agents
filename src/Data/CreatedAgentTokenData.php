<?php

namespace Packstub\Agents\Data;

use Packstub\Agents\Models\AgentToken;

readonly class CreatedAgentTokenData
{
    public function __construct(
        public AgentToken $agentToken,
        public string $plainTextToken,
    ) {}
}
