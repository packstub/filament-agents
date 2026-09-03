<?php

namespace Packstub\Agents\Filament\Resources\AgentLimits\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Packstub\Agents\Filament\Resources\AgentLimits\AgentLimitResource;
use Packstub\Agents\Support\AgentLimits;

class ManageAgentLimits extends ManageRecords
{
    protected static string $resource = AgentLimitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Add limit'))->after(fn () => AgentLimits::flush()),
        ];
    }
}
