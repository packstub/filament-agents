<?php

namespace Packstub\Agents\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Packstub\Agents\Mcp\AgentTool;
use RuntimeException;

#[IsReadOnly]
#[Description('Render a chart from numbers you already retrieved with other tools in this conversation. Only pass values that came from a tool result — never estimates. For anything over time, prefer a reporting tool that returns a chart itself, when there is one.')]
class DrawChart extends AgentTool
{
    protected function run(Request $request): array
    {
        $labels = array_values(array_map('strval', (array) $request->get('labels')));
        $datasets = collect((array) $request->get('datasets'))->map(fn ($d) => [
            'label' => (string) ($d['label'] ?? ''),
            'data' => array_values(array_map(fn ($v) => round((float) $v, 2), (array) ($d['data'] ?? []))),
        ]);

        if ($labels === [] || count($labels) > 60) {
            throw new RuntimeException(__('A chart needs between 1 and 60 labels.'));
        }
        if ($datasets->isEmpty() || $datasets->count() > 8) {
            throw new RuntimeException(__('A chart needs between 1 and 8 series.'));
        }
        foreach ($datasets as $d) {
            if (count($d['data']) !== count($labels)) {
                throw new RuntimeException(__('Series ":label" has :n values for :m labels.', ['label' => $d['label'], 'n' => count($d['data']), 'm' => count($labels)]));
            }
        }

        return [
            'chart' => [
                'type' => $request->get('type') ?: 'bar',
                'title' => (string) $request->get('title'),
                'labels' => $labels,
                'datasets' => $datasets->all(),
            ],
            'note' => 'Rendered for the user under your answer.',
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'type' => $schema->string()->enum(['bar', 'line', 'pie', 'doughnut'])->description('Default bar.'),
            'labels' => $schema->array()->items($schema->string())->required()->description('X axis (or slices).'),
            'datasets' => $schema->array()->items($schema->object([
                'label' => $schema->string()->required(),
                'data' => $schema->array()->items($schema->number())->required(),
            ]))->required()->description('One entry per series, values aligned with labels.'),
        ];
    }
}
