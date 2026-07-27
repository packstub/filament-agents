<?php

namespace Packstub\Agents\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packstub\Agents\Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('packstub-agents.rate_limit.per_minute', 3);
    }

    public function test_mcp_requests_are_rate_limited_per_token_public_id(): void
    {
        $agentToken = $this->makeAgentToken(['list-widgets' => 'read']);
        $bearer = $this->agentBearer($agentToken, 'wrong-secret');

        foreach (range(1, 3) as $attempt) {
            $this->mcpCall($bearer, 'tools/list')->assertUnauthorized();
        }

        $this->mcpCall($bearer, 'tools/list')->assertStatus(429);

        // A different token public id is not affected by the first token's limit.
        $this->mcpCall('agt_other.wrong-secret', 'tools/list')->assertUnauthorized();
    }

    public function test_mcp_requests_without_a_token_are_limited_by_ip(): void
    {
        foreach (range(1, 3) as $attempt) {
            $this->mcpCall(null, 'tools/list')->assertUnauthorized();
        }

        $this->mcpCall(null, 'tools/list')->assertStatus(429);
    }
}
