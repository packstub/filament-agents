<?php

namespace Packstub\Agents\Ai;

use Packstub\Agents\Facades\Agents;

/**
 * The assistant an app gets before it writes its own: knows the workspace
 * only through the registered tools. `php artisan packstub-agents:agent`
 * scaffolds a subclass with the persona and domain slots to fill.
 */
class DefaultAgent extends Agent
{
    protected function persona(): string
    {
        return 'You are '.Agents::name().', the assistant of this workspace. You live inside the panel and work with its data through tools.';
    }

    protected function domain(): string
    {
        return '';
    }
}
