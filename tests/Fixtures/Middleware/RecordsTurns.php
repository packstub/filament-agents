<?php

namespace Packstub\Agents\Tests\Fixtures\Middleware;

use Closure;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Packstub\Agents\Ai\Middleware\EnforceBudget;

/** An app middleware: sees the question on the way in (first step), revises it, and reads each step's answer on the way out. */
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

    public function handle(PendingStep $step, Closure $next)
    {
        $question = EnforceBudget::question($step);

        if ($step->isFirstStep() && $question !== null) {
            static::$prompts[] = $question;
        }

        if (static::$suffix !== null && $question !== null) {
            $messages = $step->messages;
            $messages[array_key_last($messages)] = new UserMessage($question.PHP_EOL.PHP_EOL.static::$suffix);
            $step = $step->withMessages($messages);
        }

        return $next($step)->then(function (StepResponse $response): void {
            static::$answers[] = $response->text;
        });
    }
}
