<?php

namespace Packstub\Agents\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Packstub\Agents\Mcp\AgentTool;
use Packstub\Agents\Support\AgentResources;
use RuntimeException;

/**
 * Show the person a live, paginated Filament table under the answer: the
 * resource's own table() with the agent's filters as the base query. The
 * tables and their filter vocabulary come from the panel's resources that
 * implement AgentResource, so the schema the model sees is generated.
 */
#[IsReadOnly]
class ShowTable extends AgentTool
{
    public function description(): string
    {
        $tables = collect(AgentResources::all())->map(fn (string $resource, string $key) => $key.' ('.$resource::getPluralModelLabel().')')->join(', ');

        return 'Show the person a live, paginated table of '.($tables ?: 'records').' — the same table as the panel page, with its search, filters, sorting and row actions. Use it whenever someone wants to see or work through a list (more than a handful of rows, "show me", "list", "table"), instead of typing rows yourself. Filters use the same vocabulary as the search tools.';
    }

    protected function run(Request $request): array
    {
        $key = (string) $request->get('table');
        $resource = AgentResources::find($key);

        if (! $resource::canViewAny()) {
            throw new RuntimeException(__('You cannot see :table.', ['table' => $key]));
        }

        $filters = AgentResources::normalizeFilters($key, (array) ($request->get('filters') ?? []));
        $total = AgentResources::apply($key, $resource::getEloquentQuery(), $filters)->count();

        return [
            'table' => [
                'resource' => $key,
                'filters' => $filters,
                'title' => (string) ($request->get('title') ?: ''),
            ],
            'total' => $total,
            'note' => 'An interactive table with these '.$total.' rows is rendered for the user under your answer (paginated, sortable, with row actions). Do not list the rows; one sentence on what the table shows is enough.',
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'table' => $schema->string()->enum(array_keys(AgentResources::all()) ?: ['none'])->required(),
            'title' => $schema->string()->description('Short caption, e.g. "Orders waiting for a phone call".'),
            'filters' => $schema->object(AgentResources::filterSchema($schema))->description('Only the keys that apply to the chosen table.'),
        ];
    }
}
