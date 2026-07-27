<?php

namespace Packstub\Agents\Filament\Resources\AgentAuditEntries\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AgentAuditEntryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('agentToken.name')
                    ->label('Agent token')
                    ->placeholder('(deleted)'),
                TextEntry::make('tool'),
                TextEntry::make('mode')
                    ->badge(),
                TextEntry::make('decision')
                    ->badge(),
                TextEntry::make('summary')
                    ->placeholder('-')
                    ->columnSpanFull(),
                KeyValueEntry::make('arguments')
                    ->columnSpanFull()
                    ->placeholder('No arguments'),
                TextEntry::make('ip')
                    ->label('IP address')
                    ->placeholder('-'),
            ]);
    }
}
