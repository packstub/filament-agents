<?php

use Packstub\Agents\Http\Middleware\AuthenticateAgent;

/*
|--------------------------------------------------------------------------
| Packstub Agents — the in-panel assistant and the MCP server
|--------------------------------------------------------------------------
|
| Provider credentials live in config/ai.php (ANTHROPIC_API_KEY, OPENAI_API_KEY, GEMINI_API_KEY, XAI_API_KEY…).
| This file says which provider the platform uses by default, which models the
| picker offers and how much a workspace may spend. Most of it can also be set
| fluently on AgentsPlugin in the panel provider; the plugin mirrors those
| values here so every runtime (queue, console, MCP requests) sees one truth.
|
*/

return [
    // How the assistant introduces itself in the panel ("Ask Acme"). AgentsPlugin::make()->name() overrides it.
    'name' => env('AGENT_NAME', 'Assistant'),

    // The panel the assistant lives in. Set by AgentsPlugin when it registers; only set it here for a headless install.
    'panel' => null,

    // 'anthropic', 'openai', 'gemini' or 'xai' have picker entries below; any other laravel/ai text provider (ollama,
    // openrouter, mistral, groq, deepseek…) runs on its smartest and cheapest models. A workspace may bring its own.
    'provider' => env('AGENT_PROVIDER', 'anthropic'),

    // null = enabled when a key exists for the provider (platform or workspace). AGENT_ENABLED=false hides the chat.
    'enabled' => env('AGENT_ENABLED'),

    // What the model picker offers, per provider. A null model means "the provider's smartest" (auto, deep) or
    // "the provider's cheapest" (fast) as laravel/ai knows them; AGENT_MODEL* pin explicit names. Effort is
    // passed as Anthropic output_config.effort, OpenAI and xAI reasoning.effort (reasoning models only) or
    // Gemini's thinking level (low, medium, high; xhigh is sent as high). A provider without entries here gets
    // Auto (smartest) and Fast (cheapest) with no effort.
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
        'gemini' => [
            'auto' => ['label' => 'Auto', 'model' => env('AGENT_MODEL', 'gemini-3.8-flash'), 'effort' => 'medium'],
            'fast' => ['label' => 'Fast', 'model' => env('AGENT_MODEL_FAST', 'gemini-3.5-flash-lite'), 'effort' => 'low'],
            'deep' => ['label' => 'Deep', 'model' => env('AGENT_MODEL_DEEP', 'gemini-3.8-flash'), 'effort' => 'high'],
        ],
        'xai' => [
            'auto' => ['label' => 'Auto', 'model' => env('AGENT_MODEL', 'grok-4.6'), 'effort' => 'medium'],
            'fast' => ['label' => 'Fast', 'model' => env('AGENT_MODEL_FAST', 'grok-4.6'), 'effort' => 'low'],
            'deep' => ['label' => 'Deep', 'model' => env('AGENT_MODEL_DEEP', 'grok-4.6'), 'effort' => 'xhigh'],
        ],
    ],

    // How many tool round-trips one turn may take before the agent has to answer, and how long an answer may be.
    'max_steps' => 12,
    'max_tokens' => 4096,

    // Long chats replay fewer messages: the answers are short and every replayed message is billed again.
    'max_conversation_messages' => 40,

    // Your own agent middleware, run on every turn after the package's guard rails (the budget check): classes
    // with handle(AgentPrompt $prompt, Closure $next) — an audit log, redaction, a tenant check. Throw
    // Packstub\Agents\Exceptions\TurnRefused to stop a turn with a message the person reads under their
    // question. AgentsPlugin::make()->middleware([...]) appends to this list.
    'middleware' => [],

    // What a long chat replays: the most recent messages that fit the token budget (estimated from what is stored),
    // cut on turn boundaries so a tool call keeps its result. Older tool results are replaced by a one-line placeholder,
    // and what falls out of the window is folded into a rolling summary the model reads first. The chat page shows a
    // context meter and, from notice_share of the budget, suggests continuing in a new chat.
    'history' => [
        'max_tokens' => (int) env('AGENT_HISTORY_MAX_TOKENS', 24000),
        'keep_tool_results_turns' => 3,
        'notice_share' => 0.7,
    ],

    // How a chat turn runs. The answer is produced by the RunAgentTurn job, which writes what it has so far to the
    // agent_turns table; the page polls it, so an answer survives a reload, a closed tab and shows in every tab of the
    // chat, and Stop can cut it short.
    'chat' => [
        // 'queue' hands the job to a queue worker (the default; run one). 'sync' runs it inside the request that asked —
        // no worker needed, everything else the same, except that an answer ends with the tab that asked for it.
        // AgentsPlugin::make()->chat(driver: 'sync') sets it from the panel provider.
        'driver' => env('AGENT_TURN_DRIVER', 'queue'),
        // Where the job goes on the queue driver. A null connection or queue means the app's default.
        'queue_connection' => env('AGENT_QUEUE_CONNECTION'),
        'queue' => env('AGENT_QUEUE'),
        // How long one turn may run on the worker, in seconds (every tool round-trip included). A turn whose job
        // stopped writing for longer than this is shown as failed, with a Retry.
        'job_timeout' => (int) env('AGENT_JOB_TIMEOUT', 600),
        // How often the page asks for the answer so far while a turn runs, in milliseconds.
        'poll_interval' => (int) env('AGENT_POLL_INTERVAL', 600),
    ],

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
