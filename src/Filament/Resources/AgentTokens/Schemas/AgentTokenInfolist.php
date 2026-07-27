<?php

namespace Packstub\Agents\Filament\Resources\AgentTokens\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AgentTokenInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('public_id'),
                KeyValueEntry::make('grants')
                    ->keyLabel('Tool')
                    ->valueLabel('Mode')
                    ->columnSpanFull()
                    ->placeholder('No grants'),
                TextEntry::make('last_used_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('expires_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('revoked_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
