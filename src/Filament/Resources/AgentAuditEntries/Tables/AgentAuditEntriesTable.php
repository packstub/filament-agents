<?php

namespace Packstub\Agents\Filament\Resources\AgentAuditEntries\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Packstub\Agents\Enums\AuditDecision;
use Packstub\Agents\Models\AgentAuditEntry;

class AgentAuditEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('agentToken.name')
                    ->label('Agent token')
                    ->placeholder('(deleted)'),
                TextColumn::make('tool')
                    ->searchable(),
                TextColumn::make('mode')
                    ->badge(),
                TextColumn::make('decision')
                    ->badge()
                    ->color(fn (AgentAuditEntry $record): string => match ($record->decision) {
                        AuditDecision::Allowed, AuditDecision::Applied => 'success',
                        AuditDecision::Denied, AuditDecision::Rejected => 'danger',
                        AuditDecision::PendingApproval => 'warning',
                        AuditDecision::Approved => 'info',
                    }),
                TextColumn::make('summary')
                    ->limit(60)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('decision')
                    ->options(AuditDecision::options()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
