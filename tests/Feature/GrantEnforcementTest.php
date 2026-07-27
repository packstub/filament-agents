<?php

namespace Packstub\Agents\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packstub\Agents\Enums\AuditDecision;
use Packstub\Agents\Models\AgentAuditEntry;
use Packstub\Agents\Tests\Fixtures\Models\Widget;
use Packstub\Agents\Tests\TestCase;

class GrantEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_tools_list_only_contains_granted_tools(): void
    {
        $agentToken = $this->makeAgentToken(['list-widgets' => 'read']);

        $response = $this->mcpCall($this->agentBearer($agentToken), 'tools/list');

        $toolNames = collect($response->json('result.tools'))->pluck('name');

        $this->assertTrue($toolNames->contains('list-widgets'));
        $this->assertFalse($toolNames->contains('update-widget'));
        $this->assertFalse($toolNames->contains('delete-widget'));
    }

    public function test_calling_an_ungranted_tool_returns_not_found_and_audits_a_denial(): void
    {
        $agentToken = $this->makeAgentToken(['list-widgets' => 'read']);

        $response = $this->mcpCall($this->agentBearer($agentToken), 'tools/call', [
            'name' => 'update-widget',
            'arguments' => ['id' => 1, 'name' => 'Sneaky'],
        ]);

        $this->assertStringContainsString('not found', (string) $response->json('error.message'));

        $entry = AgentAuditEntry::query()
            ->where('tool', 'update-widget')
            ->where('decision', AuditDecision::Denied->value)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($agentToken->id, $entry->agent_token_id);
    }

    public function test_a_grant_below_the_required_mode_is_denied_and_audited(): void
    {
        $widget = Widget::query()->create(['name' => 'Original']);

        $agentToken = $this->makeAgentToken(['update-widget' => 'read']);

        $listResponse = $this->mcpCall($this->agentBearer($agentToken), 'tools/list');
        $this->assertTrue(collect($listResponse->json('result.tools'))->pluck('name')->contains('update-widget'));

        $response = $this->mcpCall($this->agentBearer($agentToken), 'tools/call', [
            'name' => 'update-widget',
            'arguments' => ['id' => $widget->id, 'name' => 'Changed'],
        ]);

        $response->assertOk();
        $this->assertStringContainsString('not granted', json_encode($response->json('result')));

        $entry = AgentAuditEntry::query()
            ->where('tool', 'update-widget')
            ->where('decision', AuditDecision::Denied->value)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('write', $entry->mode->value);
        $this->assertSame('Original', $widget->fresh()->name);
    }

    public function test_allowed_calls_are_audited_with_redacted_arguments(): void
    {
        $agentToken = $this->makeAgentToken(['list-widgets' => 'read']);

        $this->mcpCall($this->agentBearer($agentToken), 'tools/call', [
            'name' => 'list-widgets',
            'arguments' => ['api_key' => 'super-secret-value'],
        ])->assertOk();

        $entry = AgentAuditEntry::query()
            ->where('tool', 'list-widgets')
            ->where('decision', AuditDecision::Allowed->value)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('[redacted]', $entry->arguments['api_key'] ?? null);
        $this->assertNotNull($entry->ip);
    }
}
