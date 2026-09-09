<?php

namespace Packstub\Agents\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Models\AgentLimit;
use Packstub\Agents\Models\AgentTurn;

/**
 * AI turns (the operator panel): every answer as one row — who asked and
 * where, the provider and model that answered, tokens in and out, the tools
 * called, the wall time and how it ended. The same record the log line
 * carries, without the log. Gated like the AI limits resource.
 */
class TurnLog extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 21;

    protected static ?string $slug = 'agent-turns';

    protected string $view = 'packstub-agents::pages.turn-log';

    public static function canAccess(): bool
    {
        return Agents::canManageLimits();
    }

    public static function getNavigationLabel(): string
    {
        return __('AI turns');
    }

    public function getTitle(): string|Htmlable
    {
        return __('AI turns');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Every answer of the assistant: who asked, which model answered, what it cost and how it ended.');
    }

    public function table(Table $table): Table
    {
        $number = fn ($state) => $state === null ? null : number_format((int) $state);

        return $table
            ->query(fn () => AgentTurn::query())
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('When'))->since()->sortable()
                    ->tooltip(fn (AgentTurn $turn) => $turn->created_at?->toDateTimeString()),
                TextColumn::make('participant_id')->label(__('Who'))->placeholder('—')
                    ->state(fn (AgentTurn $turn) => $turn->participant_id === null ? null : (AgentLimit::userLabel($turn->participant_id) ?? (string) $turn->participant_id)),
                TextColumn::make('tenant')->label(__('Workspace'))->placeholder('—')
                    ->visible(AgentLimit::tenantModel() !== null)
                    ->state(fn (AgentTurn $turn) => $turn->tenant === null ? null : (AgentLimit::tenantName($turn->tenant) ?? $turn->tenant)),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state) => self::statusLabel($state))
                    ->color(fn (string $state) => match ($state) {
                        AgentTurn::DONE => 'success',
                        AgentTurn::FAILED => 'danger',
                        AgentTurn::STOPPED => 'warning',
                        AgentTurn::RUNNING => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('model_name')->label(__('Model'))->placeholder('—')
                    ->description(fn (AgentTurn $turn) => $turn->provider)
                    ->searchable(),
                TextColumn::make('tokens_in')->label(__('Tokens in'))->placeholder('—')->alignEnd()
                    ->state(fn (AgentTurn $turn) => $number($turn->tokensIn())),
                TextColumn::make('tokens_out')->label(__('Tokens out'))->placeholder('—')->alignEnd()
                    ->state(fn (AgentTurn $turn) => $number($turn->tokensOut())),
                TextColumn::make('tool_calls')->label(__('Tools'))->alignEnd()
                    ->state(fn (AgentTurn $turn) => count($turn->tool_calls ?? []))
                    ->tooltip(fn (AgentTurn $turn) => implode(', ', $turn->tool_calls ?? []) ?: null),
                TextColumn::make('duration_ms')->label(__('Duration'))->placeholder('—')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => number_format(((int) $state) / 1000, 1).' s'),
                TextColumn::make('finish_reason')->label(__('Ended'))->placeholder('—')
                    ->formatStateUsing(fn (string $state) => Str::headline($state)),
                TextColumn::make('error')->label(__('Error'))->placeholder('—')->limit(50)
                    ->tooltip(fn (AgentTurn $turn) => $turn->error)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')->label(__('Turn'))->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))
                    ->options(collect([AgentTurn::QUEUED, AgentTurn::PENDING, AgentTurn::RUNNING, AgentTurn::DONE, AgentTurn::STOPPED, AgentTurn::FAILED])
                        ->mapWithKeys(fn (string $status) => [$status => self::statusLabel($status)])->all()),
                SelectFilter::make('provider')->label(__('Provider'))
                    ->options(fn () => AgentTurn::query()->whereNotNull('provider')->distinct()->orderBy('provider')->pluck('provider', 'provider')->all()),
            ])
            ->emptyStateHeading(__('No turns yet'))
            ->emptyStateDescription(__('Turns appear here as soon as someone asks the assistant a question.'));
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            AgentTurn::QUEUED => __('Queued'),
            AgentTurn::PENDING => __('Pending'),
            AgentTurn::RUNNING => __('Running'),
            AgentTurn::DONE => __('Done'),
            AgentTurn::STOPPED => __('Stopped'),
            AgentTurn::FAILED => __('Failed'),
            default => $status,
        };
    }
}
