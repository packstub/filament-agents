<?php

namespace Packstub\Agents\Filament\Resources\AgentAuditEntries;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Packstub\Agents\Filament\Resources\AgentAuditEntries\Pages\ListAgentAuditEntries;
use Packstub\Agents\Filament\Resources\AgentAuditEntries\Pages\ViewAgentAuditEntry;
use Packstub\Agents\Filament\Resources\AgentAuditEntries\Schemas\AgentAuditEntryInfolist;
use Packstub\Agents\Filament\Resources\AgentAuditEntries\Tables\AgentAuditEntriesTable;
use Packstub\Agents\Models\AgentAuditEntry;

class AgentAuditEntryResource extends Resource
{
    protected static ?string $model = AgentAuditEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Agents';

    protected static ?string $modelLabel = 'audit entry';

    protected static ?string $pluralModelLabel = 'audit log';

    public static function infolist(Schema $schema): Schema
    {
        return AgentAuditEntryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AgentAuditEntriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentAuditEntries::route('/'),
            'view' => ViewAgentAuditEntry::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
