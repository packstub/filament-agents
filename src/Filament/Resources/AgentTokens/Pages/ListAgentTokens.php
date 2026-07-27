<?php

namespace Packstub\Agents\Filament\Resources\AgentTokens\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Packstub\Agents\Filament\Resources\AgentTokens\AgentTokenResource;

class ListAgentTokens extends ListRecords
{
    protected static string $resource = AgentTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
