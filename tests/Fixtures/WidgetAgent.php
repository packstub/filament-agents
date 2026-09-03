<?php

namespace Packstub\Agents\Tests\Fixtures;

use Packstub\Agents\Ai\Agent;
use Packstub\Agents\Tests\Fixtures\Models\Widget;

class WidgetAgent extends Agent
{
    protected function persona(): string
    {
        return 'You are Ask Widgets, the assistant of a widget catalogue.';
    }

    protected function domain(): string
    {
        return '- Widgets have a name, a status (draft, live, retired) and a price.';
    }

    protected function context(): array
    {
        return [
            ...parent::context(),
            'Widgets in the catalogue: '.Widget::query()->count().'.',
        ];
    }
}
