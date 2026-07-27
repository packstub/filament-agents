<?php

namespace Packstub\Agents\Filament\Resources\PendingApprovals;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Packstub\Agents\Filament\Resources\PendingApprovals\Pages\ListPendingApprovals;
use Packstub\Agents\Filament\Resources\PendingApprovals\Pages\ViewPendingApproval;
use Packstub\Agents\Filament\Resources\PendingApprovals\Schemas\PendingApprovalInfolist;
use Packstub\Agents\Filament\Resources\PendingApprovals\Tables\PendingApprovalsTable;
use Packstub\Agents\Models\PendingApproval;

class PendingApprovalResource extends Resource
{
    protected static ?string $model = PendingApproval::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Agents';

    public static function infolist(Schema $schema): Schema
    {
        return PendingApprovalInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PendingApprovalsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPendingApprovals::route('/'),
            'view' => ViewPendingApproval::route('/{record}'),
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
}
