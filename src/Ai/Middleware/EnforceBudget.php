<?php

namespace Packstub\Agents\Ai\Middleware;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Packstub\Agents\Exceptions\TurnRefused;
use Packstub\Agents\Support\AgentBudget;

/**
 * The spending guard rails, on every turn whatever asked for it: the chat
 * page's job, a console command, an app that prompts the agent directly. A
 * turn over a limit is refused with the reason before the provider is
 * called; one that may run is counted against the per-minute limit.
 */
class EnforceBudget
{
    public function handle(AgentPrompt $prompt, Closure $next)
    {
        if ($refusal = AgentBudget::refusal($prompt->hasApprovalDecisions() ? null : $prompt->prompt)) {
            throw new TurnRefused($refusal);
        }

        AgentBudget::hit();

        return $next($prompt);
    }
}
