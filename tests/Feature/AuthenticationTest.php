<?php

namespace Packstub\Agents\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packstub\Agents\Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mcp_requests_without_a_bearer_token_are_rejected(): void
    {
        $this->mcpCall(null, 'tools/list')->assertUnauthorized();
    }

    public function test_mcp_requests_with_a_malformed_token_are_rejected(): void
    {
        $this->mcpCall('token-without-a-separator', 'tools/list')->assertUnauthorized();
    }

    public function test_mcp_requests_with_an_unknown_public_id_are_rejected(): void
    {
        $this->mcpCall('agt_unknown.some-secret', 'tools/list')->assertUnauthorized();
    }

    public function test_mcp_requests_with_a_wrong_secret_are_rejected(): void
    {
        $agentToken = $this->makeAgentToken(['list-widgets' => 'read']);

        $this->mcpCall($this->agentBearer($agentToken, 'wrong-secret'), 'tools/list')->assertUnauthorized();
    }

    public function test_revoked_agent_tokens_are_rejected(): void
    {
        $agentToken = $this->makeAgentToken(['list-widgets' => 'read'], attributes: ['revoked_at' => now()]);

        $this->mcpCall($this->agentBearer($agentToken), 'tools/list')->assertUnauthorized();
    }

    public function test_expired_agent_tokens_are_rejected(): void
    {
        $agentToken = $this->makeAgentToken(['list-widgets' => 'read'], attributes: ['expires_at' => now()->subMinute()]);

        $this->mcpCall($this->agentBearer($agentToken), 'tools/list')->assertUnauthorized();
    }

    public function test_a_valid_agent_token_can_list_tools_and_last_used_at_is_updated(): void
    {
        $agentToken = $this->makeAgentToken(['list-widgets' => 'read']);

        $this->assertNull($agentToken->last_used_at);

        $response = $this->mcpCall($this->agentBearer($agentToken), 'tools/list');

        $response->assertOk();
        $this->assertIsArray($response->json('result.tools'));
        $this->assertNotNull($agentToken->fresh()->last_used_at);
    }

    public function test_get_requests_to_the_mcp_endpoint_are_not_allowed(): void
    {
        $this->get((string) config('packstub-agents.route.path'))->assertStatus(405);
    }
}
