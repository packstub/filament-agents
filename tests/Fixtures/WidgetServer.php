<?php

namespace Packstub\FilamentAgents\Tests\Fixtures;

use Packstub\Agents\Mcp\AgentServer;
use Packstub\Agents\Mcp\Tools\DrawChart;
use Packstub\FilamentAgents\Mcp\Tools\ShowTable;
use Packstub\FilamentAgents\Tests\Fixtures\Tools\ListWidgets;
use Packstub\FilamentAgents\Tests\Fixtures\Tools\RenameWidget;

class WidgetServer extends AgentServer
{
    protected string $name = 'Widgets';

    protected string $instructions = 'A catalogue of widgets. Start with list-widgets.';

    protected array $tools = [
        ListWidgets::class,
        RenameWidget::class,
        ShowTable::class,
        DrawChart::class,
    ];
}
