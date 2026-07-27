<?php

namespace Packstub\Agents\Filament\Resources\AgentTokens\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Packstub\Agents\Models\AgentToken;

class AgentTokensTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('public_id')
                    ->searchable(),
                TextColumn::make('grants')
                    ->label('Grants')
                    ->state(fn (AgentToken $record): int => count($record->grants ?? []))
                    ->badge(),
                TextColumn::make('last_used_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('revoked_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—')
                    ->color('danger'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
