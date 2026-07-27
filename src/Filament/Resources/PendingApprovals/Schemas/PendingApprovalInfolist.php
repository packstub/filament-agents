<?php

namespace Packstub\Agents\Filament\Resources\PendingApprovals\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Packstub\Agents\Models\PendingApproval;

class PendingApprovalInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('tool'),
                TextEntry::make('agentToken.name')
                    ->label('Agent token')
                    ->placeholder('(deleted)'),
                TextEntry::make('status')
                    ->badge()
                    ->color(fn (PendingApproval $record): string => $record->status->color()),
                TextEntry::make('proposed_changes')
                    ->label('Proposed changes')
                    ->state(fn (PendingApproval $record): array => collect($record->proposed_changes ?? [])
                        ->map(fn (array $change, string $field): string => sprintf(
                            '%s: %s → %s',
                            $field,
                            json_encode($change['from'] ?? null),
                            json_encode($change['to'] ?? null),
                        ))
                        ->values()
                        ->all())
                    ->listWithLineBreaks()
                    ->columnSpanFull(),
                KeyValueEntry::make('arguments')
                    ->columnSpanFull()
                    ->placeholder('No arguments'),
                KeyValueEntry::make('result')
                    ->columnSpanFull()
                    ->placeholder('Not applied'),
                TextEntry::make('decidedBy.name')
                    ->label('Decided by')
                    ->placeholder('-'),
                TextEntry::make('decided_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('applied_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime(),
            ]);
    }
}
