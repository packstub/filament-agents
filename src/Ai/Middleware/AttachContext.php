<?php

namespace Packstub\Agents\Ai\Middleware;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Packstub\Agents\Ai\Agent;

/**
 * The dynamic block (date and time, workspace, person, language, page
 * context) rides with the question instead of the system prompt, so the
 * instructions, the tool list and the history in front of it are
 * byte-identical from one turn to the next and the provider's prompt cache
 * keeps hitting. It runs last: the app's middleware reads the question as
 * typed. A turn that resumes an approval has no question to carry it and
 * goes without — the model continues the step the block already informed.
 */
class AttachContext
{
    public function handle(AgentPrompt $prompt, Closure $next)
    {
        if ($prompt->hasApprovalDecisions() || ! $prompt->agent instanceof Agent) {
            return $next($prompt);
        }

        return $next($prompt->prepend($prompt->agent->dynamicInstructions()));
    }
}
