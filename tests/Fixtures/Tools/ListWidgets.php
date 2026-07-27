<?php

namespace Packstub\Agents\Tests\Fixtures\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Packstub\Agents\Authorization\AgentGate;
use Packstub\Agents\Enums\ToolMode;
use Packstub\Agents\Mcp\GovernedTool;
use Packstub\Agents\Tests\Fixtures\Models\Widget;

#[IsReadOnly]
#[IsIdempotent]
class ListWidgets extends GovernedTool
{
    protected string $description = 'List all widgets.';

    public static function requiredMode(): ToolMode
    {
        return ToolMode::Read;
    }

    public function handle(Request $request, AgentGate $gate): ResponseFactory|Response
    {
        $this->authorizeOrFail($gate, $request);

        return Response::structured([
            'widgets' => Widget::query()
                ->get()
                ->map(fn (Widget $widget): array => [
                    'id' => $widget->id,
                    'name' => $widget->name,
                    'description' => $widget->description,
                ])
                ->all(),
        ]);
    }
}
