<?php

namespace Packstub\Agents\Filament\Resources\AgentTokens;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Packstub\Agents\Filament\Resources\AgentTokens\Pages\CreateAgentToken;
use Packstub\Agents\Filament\Resources\AgentTokens\Pages\EditAgentToken;
use Packstub\Agents\Filament\Resources\AgentTokens\Pages\ListAgentTokens;
use Packstub\Agents\Filament\Resources\AgentTokens\Pages\ViewAgentToken;
use Packstub\Agents\Filament\Resources\AgentTokens\Schemas\AgentTokenForm;
use Packstub\Agents\Filament\Resources\AgentTokens\Schemas\AgentTokenInfolist;
use Packstub\Agents\Filament\Resources\AgentTokens\Tables\AgentTokensTable;
use Packstub\Agents\Models\AgentToken;

class AgentTokenResource extends Resource
{
    protected static ?string $model = AgentToken::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|\UnitEnum|null $navigationGroup = 'Agents';

    public static function form(Schema $schema): Schema
    {
        return AgentTokenForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AgentTokenInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AgentTokensTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentTokens::route('/'),
            'create' => CreateAgentToken::route('/create'),
            'view' => ViewAgentToken::route('/{record}'),
            'edit' => EditAgentToken::route('/{record}/edit'),
        ];
    }
}
