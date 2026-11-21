<?php

use Packstub\Agents\Support\AgentConversationStore;
use Packstub\FilamentAgents\Tests\ChatTester;
use Packstub\FilamentAgents\Tests\TestCase;
use Pest\TestSuite;

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

/**
 * The panel's chat, driven over HTTP as the browser drives it (ChatTester): the page is opened, then every action is
 * a request to the panel's chat endpoints.
 *
 * @param  array<string, mixed>  $parameters  the page's route parameters (conversation)
 * @param  array<string, mixed>  $query  its query string (context, embedded, prompt)
 */
function chat(array $parameters = [], array $query = []): ChatTester
{
    return new ChatTester(TestSuite::getInstance()->test, $parameters, $query);
}
