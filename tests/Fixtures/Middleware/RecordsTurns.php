<?php

namespace Packstub\Agents\Tests\Fixtures\Middleware;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

/** An app middleware: sees the prompt on the way in, revises it, and reads the answer on the way out. */
class RecordsTurns
{
    /** @var list<string> */
    public static array $prompts = [];

    /** @var list<string> */
    public static array $answers = [];

    public static ?string $suffix = null;

    public static function reset(): void
    {
        static::$prompts = [];
        static::$answers = [];
        static::$suffix = null;
    }

    public function handle(AgentPrompt $prompt, Closure $next)
    {
        static::$prompts[] = $prompt->prompt;

        $prompt = static::$suffix !== null ? $prompt->append(static::$suffix) : $prompt;

        return $next($prompt)->then(function (AgentResponse $response): void {
            static::$answers[] = $response->text;
        });
    }
}
