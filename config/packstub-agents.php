<?php

use Packstub\Agents\Http\Middleware\AuthenticateAgent;

/*
|--------------------------------------------------------------------------
| Packstub Agents — the in-panel assistant and the MCP server
|--------------------------------------------------------------------------
|
| Provider credentials live in config/ai.php (ANTHROPIC_API_KEY, OPENAI_API_KEY…).
| This file says which provider the platform uses by default, which models the
| picker offers and how much a workspace may spend. Most of it can also be set
| fluently on AgentsPlugin in the panel provider; the plugin mirrors those
| values here so every runtime (queue, console, MCP requests) sees one truth.
|
*/

return [
    // How the assistant introduces itself in the panel ("Ask Orderflux"). AgentsPlugin::make()->name() overrides it.
    'name' => env('AGENT_NAME', 'Assistant'),

    // The panel the assistant lives in. Set by AgentsPlugin when it registers; only set it here for a headless install.
    'panel' => null,

    // 'anthropic' or 'openai' (any laravel/ai text provider works). The platform default; a workspace may bring its own.
    'provider' => env('AGENT_PROVIDER', 'anthropic'),

    // null = enabled when a key exists for the provider (platform or workspace). AGENT_ENABLED=false hides the chat.
    'enabled' => env('AGENT_ENABLED'),

    // What the model picker offers, per provider. A null model means "the provider's smartest" (auto, deep) or
    // "the provider's cheapest" (fast) as laravel/ai knows them; AGENT_MODEL* pin explicit names. Effort is
    // passed as Anthropic output_config.effort / OpenAI reasoning.effort.
    'models' => [
        'anthropic' => [
            'auto' => ['label' => 'Auto', 'model' => env('AGENT_MODEL', 'claude-opus-5'), 'effort' => 'medium'],
            'fast' => ['label' => 'Fast', 'model' => env('AGENT_MODEL_FAST', 'claude-haiku-4-5'), 'effort' => null],
            'deep' => ['label' => 'Deep', 'model' => env('AGENT_MODEL_DEEP', 'claude-opus-5'), 'effort' => 'xhigh'],
        ],
        'openai' => [
            'auto' => ['label' => 'Auto', 'model' => env('AGENT_MODEL'), 'effort' => 'medium'],
            'fast' => ['label' => 'Fast', 'model' => env('AGENT_MODEL_FAST'), 'effort' => 'low'],
            'deep' => ['label' => 'Deep', 'model' => env('AGENT_MODEL_DEEP'), 'effort' => 'high'],
        ],
    ],

    // How many tool round-trips one turn may take before the agent has to answer, and how long an answer may be.
    'max_steps' => 12,
    'max_tokens' => 4096,

    // Long chats replay fewer messages: the answers are short and every replayed message is billed again.
    'max_conversation_messages' => 40,

    // Spending guard rails, enforced before a turn calls the provider (this file is the platform's ceiling; the
    // operator's AI limits page overrides it per workspace and per user; the provider's own hard spend limit is
    // the real backstop). null disables a limit.
    'limits' => [
        'turns_per_minute' => (int) env('AGENT_TURNS_PER_MINUTE', 6),      // per user
        'turns_per_day' => (int) env('AGENT_TURNS_PER_DAY', 150),          // per workspace
        'tokens_per_month' => (int) env('AGENT_TOKENS_PER_MONTH', 3000000), // per workspace, all token kinds
        'user_tokens_per_day' => (int) env('AGENT_USER_TOKENS_PER_DAY', 100000),      // per user, inside a workspace
        'user_tokens_per_month' => (int) env('AGENT_USER_TOKENS_PER_MONTH', 1500000), // per user, inside a workspace
        'prompt_max_chars' => (int) env('AGENT_PROMPT_MAX_CHARS', 2000),
    ],

    // The database connection of the agent_limits table. null = the default connection. Database-per-tenant apps
    // point it at the central connection, since limits are the operator's, not the workspace's.
    'limits_connection' => env('AGENT_LIMITS_CONNECTION'),

    // The MCP server for external agents (Claude Code, Claude Desktop, Cursor…). Bearer = a token from the
    // Agent access page. Put {tenant} in the path when the panel has tenancy: "mcp/{tenant}".
    'mcp' => [
        'enabled' => (bool) env('AGENT_MCP_ENABLED', true),
        'path' => 'mcp',
        // An AgentServer subclass with your name, instructions and tool list; null = the package's server with the
        // tools registered on the plugin.
        'server' => null,
        'middleware' => ['throttle:60,1', 'auth:sanctum', AuthenticateAgent::class],
    ],

    // Migrations auto-run from the package by default. Database-per-tenant apps set this to false, publish them
    // (vendor:publish --tag=packstub-agents-migrations) and move the chat tables into the tenant migrations.
    'run_migrations' => true,
];
