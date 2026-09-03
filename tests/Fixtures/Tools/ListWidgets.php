<?php

namespace Packstub\Agents\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Packstub\Agents\Mcp\AgentTool;
use Packstub\Agents\Support\AgentResources;
use Packstub\Agents\Tests\Fixtures\Filament\Resources\Widgets\WidgetResource;
use Packstub\Agents\Tests\Fixtures\Models\Widget;

#[IsReadOnly]
#[Description('Find widgets by name, status and price.')]
class ListWidgets extends AgentTool
{
    protected ?string $ability = 'widgets.view';

    protected function run(Request $request): array
    {
        $filters = AgentResources::normalizeFilters('widgets', (array) ($request->get('filters') ?? []));
        $query = AgentResources::apply('widgets', Widget::query(), $filters);

        return [
            'total' => $query->count(),
            'rows' => $query->limit($this->limit($request))->get()->map(fn (Widget $w) => WidgetResource::agentSummary($w))->all(),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'filters' => $schema->object(AgentResources::filterSchema($schema, 'widgets')),
            'limit' => $schema->integer(),
        ];
    }
}
