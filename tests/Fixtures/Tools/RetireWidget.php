<?php

namespace Packstub\Agents\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Packstub\Agents\Mcp\AgentTool;
use Packstub\Agents\Tests\Fixtures\Models\Widget;
use RuntimeException;

/** A write tool with no panel behind it (RenameWidget summarises through a Filament resource). */
#[Description('Retire a widget.')]
class RetireWidget extends AgentTool
{
    protected ?string $ability = 'widgets.manage';

    protected function run(Request $request): array
    {
        $widget = Widget::query()->find((int) $request->get('id')) ?? throw new RuntimeException('No widget matches '.$request->get('id').'.');
        $widget->update(['status' => 'retired']);

        return ['retired' => true, 'id' => $widget->id];
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required()];
    }
}
