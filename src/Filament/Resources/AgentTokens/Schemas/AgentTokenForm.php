<?php

namespace Packstub\Agents\Filament\Resources\AgentTokens\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Packstub\Agents\Enums\ToolMode;
use Packstub\Agents\Support\ToolRegistry;

class AgentTokenForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Repeater::make('grants')
                    ->label('Capability grants')
                    ->schema([
                        Select::make('tool')
                            ->options(ToolRegistry::options())
                            ->required(),
                        Select::make('mode')
                            ->options(ToolMode::options())
                            ->required(),
                    ])
                    ->columns(2)
                    ->columnSpanFull()
                    ->visible(fn (string $operation): bool => $operation === 'create'),
                TextInput::make('public_id')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (?object $record): bool => $record !== null),
                Placeholder::make('token_secret_help')
                    ->label('Token secret')
                    ->content('A plain secret is generated automatically when the token is created and shown once on the next screen.')
                    ->visible(fn (string $operation): bool => $operation === 'create'),
                DateTimePicker::make('expires_at'),
            ]);
    }
}
