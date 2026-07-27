<?php

namespace Packstub\Agents\Testing;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Packstub\Agents\Models\AgentToken;

/**
 * Test helpers for exercising the governed MCP endpoint. Shipped with the
 * package so host applications can use them in their own suites.
 */
trait InteractsWithAgentTokens
{
    /**
     * @param  array<string, string>  $grants  tool name => mode value
     */
    protected function makeAgentToken(array $grants = [], string $secret = 'plain-secret', array $attributes = []): AgentToken
    {
        return AgentToken::query()->create(array_merge([
            'name' => 'Test Agent',
            'public_id' => 'agt_'.Str::lower(Str::random(16)),
            'secret_hash' => Hash::make($secret),
            'grants' => $grants === [] ? null : $grants,
        ], $attributes));
    }

    protected function agentBearer(AgentToken $agentToken, string $secret = 'plain-secret'): string
    {
        return $agentToken->public_id.'.'.$secret;
    }

    /**
     * Plant the token on the current request, the way AuthenticateAgentToken
     * does over HTTP. Required when invoking tools directly through the
     * server's testing entry point, which bypasses route middleware.
     */
    protected function actAsAgent(AgentToken $agentToken): void
    {
        $this->app['request']->attributes->set('agentToken', $agentToken);
    }

    /**
     * POST a raw JSON-RPC envelope to the configured MCP endpoint.
     */
    protected function mcpCall(?string $bearer, string $method, array $params = []): TestResponse
    {
        $request = $bearer === null ? $this : $this->withToken($bearer);

        return $request->postJson((string) config('packstub-agents.route.path'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params === [] ? (object) [] : $params,
        ]);
    }
}
