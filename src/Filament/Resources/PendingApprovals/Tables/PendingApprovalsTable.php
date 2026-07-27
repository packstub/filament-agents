<?php

namespace Packstub\Agents\Filament\Resources\PendingApprovals\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Packstub\Agents\Enums\ApprovalStatus;
use Packstub\Agents\Models\PendingApproval;

class PendingApprovalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tool')
                    ->searchable(),
                TextColumn::make('agentToken.name')
                    ->label('Agent token')
                    ->placeholder('(deleted)'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (PendingApproval $record): string => $record->status->color()),
                TextColumn::make('decidedBy.name')
                    ->label('Decided by')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ApprovalStatus::options()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
