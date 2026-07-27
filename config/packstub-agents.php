<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for the MCP endpoint. When disabled the route is not
    | registered at all — tokens, approvals, and the Filament control plane
    | keep working so pending work can still be reviewed.
    |
    */

    'enabled' => env('PACKSTUB_AGENTS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | MCP Route
    |--------------------------------------------------------------------------
    |
    | The path the MCP server is exposed on, plus any extra middleware to
    | append after the package's own throttle + token-authentication stack.
    |
    */

    'route' => [
        'path' => '/mcp/agents',
        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tools
    |--------------------------------------------------------------------------
    |
    | The governed tools exposed to agents. Explicit opt-in: a tool is
    | invisible to every agent until its class is listed here AND the calling
    | agent token holds a grant for it. Each class must extend
    | Packstub\Agents\Mcp\GovernedTool.
    |
    */

    'tools' => [],

    /*
    |--------------------------------------------------------------------------
    | Migrations
    |--------------------------------------------------------------------------
    |
    | Migrations auto-run from the package by default. Consumers who need a
    | custom schema set this to false and publish the migrations instead
    | (vendor:publish --tag=packstub-agents-migrations).
    |
    */

    'run_migrations' => true,

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Requests per minute for the MCP endpoint, keyed per agent token
    | public id (falling back to the client IP for unauthenticated calls).
    |
    */

    'rate_limit' => [
        'per_minute' => (int) env('PACKSTUB_AGENTS_RATE_LIMIT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Redaction
    |--------------------------------------------------------------------------
    |
    | Argument keys whose values are replaced with "[redacted]" before an
    | audit entry is written. Matched case-insensitively as substrings of the
    | argument key, at any nesting depth.
    |
    */

    'redaction' => [
        'keys' => ['secret', 'password', 'key', 'token', 'paddle', 'ip', 'user_agent'],
    ],

];
