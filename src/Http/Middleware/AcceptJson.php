<?php

namespace Packstub\Agents\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes every MCP request one that accepts JSON, so a failed authentication
 * is answered with a 401 instead of the framework's guest redirect. A client
 * does not always send an Accept header naming JSON (laravel/mcp only
 * reorders one that does), and without it auth:sanctum sends the client to
 * the app's "login" route, which a panel-only app does not even define.
 * Runs ahead of the configured endpoint middleware; the endpoint answers in
 * JSON (or an event stream) in any case.
 */
class AcceptJson
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->wantsJson()) {
            $accept = trim((string) $request->header('Accept'));

            $request->headers->set('Accept', $accept === '' ? 'application/json' : 'application/json, '.$accept);
        }

        return $next($request);
    }
}
