<?php

namespace Packstub\Agents\Filament\Resources\AgentLimits\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;
use Packstub\Agents\Filament\Resources\AgentLimits\AgentLimitResource;
use Packstub\Agents\Support\AgentLimits;

class ManageAgentLimits extends ManageRecords
{
    protected static string $resource = AgentLimitResource::class;

    /** Filament title-cases the plural label ("AI Limits"); keep the sidebar's sentence case everywhere. */
    public function getTitle(): string|Htmlable
    {
        return __('AI limits');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Add limit'))->modalHeading(__('Add limit'))->after(fn () => AgentLimits::flush()),
        ];
    }
}
