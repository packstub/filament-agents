<?php

namespace Packstub\Agents\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Packstub\Agents\Data\CreatedAgentTokenData;
use Packstub\Agents\Enums\ToolMode;
use Packstub\Agents\Models\AgentToken;
use Packstub\Agents\Support\ToolRegistry;

class CreateAgentToken
{
    /**
     * @param  array<string, string>  $grants  tool name => mode value
     */
    public function handle(string $name, array $grants = [], ?CarbonInterface $expiresAt = null): CreatedAgentTokenData
    {
        $this->validateGrants($grants);

        $plainSecret = Str::random(48);

        $agentToken = AgentToken::query()->create([
            'name' => $name,
            'public_id' => 'agt_'.Str::lower(Str::random(16)),
            'secret_hash' => Hash::make($plainSecret),
            'grants' => $grants === [] ? null : $grants,
            'expires_at' => $expiresAt,
        ]);

        return new CreatedAgentTokenData(
            agentToken: $agentToken,
            plainTextToken: $agentToken->public_id.'.'.$plainSecret,
        );
    }

    /**
     * @param  array<string, string>  $grants
     */
    protected function validateGrants(array $grants): void
    {
        $knownTools = ToolRegistry::names();

        foreach ($grants as $toolName => $mode) {
            if (! in_array($toolName, $knownTools, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown tool [%s]. Registered tools: %s',
                    $toolName,
                    $knownTools === [] ? '(none)' : implode(', ', $knownTools),
                ));
            }

            if (ToolMode::tryFrom($mode) === null) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid mode [%s] for tool [%s]. Valid modes: %s',
                    $mode,
                    $toolName,
                    implode(', ', array_column(ToolMode::cases(), 'value')),
                ));
            }
        }
    }
}
