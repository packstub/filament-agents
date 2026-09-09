<?php

namespace Packstub\Agents\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Mcp\AgentTool;

/** Reports the runtime a tool call sees — who, which workspace, which locale — so a test can check what the context restored. */
#[IsReadOnly]
#[Description('Who is asking, in which workspace.')]
class WhoAmI extends AgentTool
{
    protected function run(Request $request): array
    {
        return [
            'user' => auth()->id(),
            'guard' => auth()->getDefaultDriver(),
            'tenant' => Agents::tenant()?->slug,
            'locale' => app()->getLocale(),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
