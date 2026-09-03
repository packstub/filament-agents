<?php

namespace Packstub\Agents\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Packstub\Agents\Mcp\AgentTool;
use Packstub\Agents\Tests\Fixtures\Filament\Resources\Widgets\WidgetResource;
use Packstub\Agents\Tests\Fixtures\Models\Widget;
use RuntimeException;

#[Description('Rename a widget.')]
class RenameWidget extends AgentTool
{
    protected ?string $ability = 'widgets.manage';

    protected function run(Request $request): array
    {
        $widget = Widget::query()->find((int) $request->get('id')) ?? throw new RuntimeException('No widget matches '.$request->get('id').'.');
        $widget->update(['name' => (string) $request->get('name')]);

        return ['renamed' => true, 'widget' => WidgetResource::agentSummary($widget)];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'name' => $schema->string()->required(),
        ];
    }
}
