<?php

namespace Packstub\Agents\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Packstub\Agents\Models\AgentToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgentToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (! is_string($bearerToken) || ! str_contains($bearerToken, '.')) {
            abort(401, 'A valid agent bearer token is required.');
        }

        [$publicId, $plainSecret] = explode('.', $bearerToken, 2);

        $agentToken = AgentToken::query()
            ->where('public_id', $publicId)
            ->first();

        if (! $agentToken instanceof AgentToken) {
            abort(401, 'The agent token was not found.');
        }

        if ($agentToken->revoked_at !== null) {
            abort(401, 'The agent token has been revoked.');
        }

        if ($agentToken->expires_at !== null && $agentToken->expires_at->isPast()) {
            abort(401, 'The agent token has expired.');
        }

        if (! Hash::check($plainSecret, $agentToken->getRawOriginal('secret_hash'))) {
            abort(401, 'The agent token secret is invalid.');
        }

        $agentToken->forceFill([
            'last_used_at' => now(),
        ])->saveQuietly();

        $request->attributes->set('agentToken', $agentToken);

        return $next($request);
    }
}
