<?php

namespace Packstub\Agents\Tests\Fixtures;

use Packstub\Agents\Mcp\AgentServer;
use Packstub\Agents\Mcp\Tools\DrawChart;
use Packstub\Agents\Mcp\Tools\ShowTable;
use Packstub\Agents\Tests\Fixtures\Tools\ListWidgets;
use Packstub\Agents\Tests\Fixtures\Tools\RenameWidget;

class WidgetServer extends AgentServer
{
    protected string $name = 'Widgets';

    protected string $instructions = 'A catalogue of widgets. Start with list_widgets.';

    protected array $tools = [
        ListWidgets::class,
        RenameWidget::class,
        ShowTable::class,
        DrawChart::class,
    ];
}
