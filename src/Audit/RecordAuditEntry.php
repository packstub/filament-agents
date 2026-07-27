<?php

namespace Packstub\Agents\Audit;

use Illuminate\Support\Str;
use Packstub\Agents\Enums\AuditDecision;
use Packstub\Agents\Enums\ToolMode;
use Packstub\Agents\Models\AgentAuditEntry;
use Packstub\Agents\Models\AgentToken;

class RecordAuditEntry
{
    public function handle(
        ?AgentToken $agentToken,
        string $tool,
        ToolMode $mode,
        AuditDecision $decision,
        array $arguments = [],
        ?string $summary = null,
    ): AgentAuditEntry {
        return AgentAuditEntry::query()->create([
            'agent_token_id' => $agentToken?->id,
            'tool' => $tool,
            'mode' => $mode,
            'decision' => $decision,
            'arguments' => $this->redact($arguments),
            'summary' => $summary,
            'ip' => request()->ip(),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return array<array-key, mixed>
     */
    protected function redact(array $arguments): array
    {
        $redactedKeys = config('packstub-agents.redaction.keys', []);

        $redacted = [];

        foreach ($arguments as $key => $value) {
            if (is_string($key) && Str::contains(Str::lower($key), $redactedKeys)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = match (true) {
                is_array($value) => $this->redact($value),
                is_string($value) => Str::limit($value, 500),
                default => $value,
            };
        }

        return $redacted;
    }
}
