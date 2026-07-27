<?php

namespace Packstub\Agents\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Packstub\Agents\Models\AgentToken;
use Packstub\Agents\Tests\TestCase;

class CreateTokenCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_token_with_parsed_grants_and_prints_the_bearer_once(): void
    {
        $this->artisan('packstub:agents:create-token', [
            'name' => 'CI Agent',
            '--grant' => ['list-widgets:read', 'update-widget:write'],
        ])
            ->expectsOutputToContain('agt_')
            ->assertSuccessful();

        $agentToken = AgentToken::query()->sole();

        $this->assertSame('CI Agent', $agentToken->name);
        $this->assertSame(
            ['list-widgets' => 'read', 'update-widget' => 'write'],
            $agentToken->grants,
        );
    }

    public function test_it_rejects_an_unknown_tool_grant(): void
    {
        $this->artisan('packstub:agents:create-token', [
            'name' => 'CI Agent',
            '--grant' => ['no-such-tool:read'],
        ])->assertFailed();

        $this->assertSame(0, AgentToken::query()->count());
    }

    public function test_it_rejects_a_malformed_grant(): void
    {
        $this->artisan('packstub:agents:create-token', [
            'name' => 'CI Agent',
            '--grant' => ['list-widgets'],
        ])->assertFailed();

        $this->assertSame(0, AgentToken::query()->count());
    }
}
