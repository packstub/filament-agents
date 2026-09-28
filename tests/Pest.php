<?php

use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/**
 * A message row written in the vocabulary laravel/ai 0.x used (tool_calls, tool_results, approval_state), in the shape
 * the table has since 1.0: its steps and status, the way the engine's upgrade migration rewrites existing rows.
 */
function legacyRow(array $row): array
{
    if (isset($row['steps'])) {
        return $row;
    }

    [$steps, $status] = AgentConversationStore::stepsFromLegacyRow($row);
    unset($row['tool_calls'], $row['tool_results'], $row['approval_state']);

    return $row + ['steps' => $steps, 'status' => $status];
}
