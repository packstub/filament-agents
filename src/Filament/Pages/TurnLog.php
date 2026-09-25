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
use Packstub\Agents\Filament\Widgets\TurnsChart;
use Packstub\Agents\Filament\Widgets\TurnStats;
use Packstub\Agents\Models\AgentLimit;
use Packstub\Agents\Models\AgentMessageFeedback;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentPricing;

/**
 * AI turns (the operator panel): the week's numbers and a chart of turns
 * per day over every answer as one row — who asked and where, the provider
 * and model that answered, tokens in and out, the cost, the tools called,
 * the wall time, how it ended and how the answer was rated. The same record
 * the log line carries, without the log. Gated like the AI limits resource.
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

    protected function getHeaderWidgets(): array
    {
        return [TurnStats::class, TurnsChart::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
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
                TextColumn::make('cost')->label(__('Cost'))->placeholder('—')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => AgentPricing::format($state === null ? null : (float) $state))
                    ->toggleable(),
                TextColumn::make('tool_calls')->label(__('Tools'))->alignEnd()
                    ->state(fn (AgentTurn $turn) => count($turn->tool_calls ?? []))
                    ->tooltip(fn (AgentTurn $turn) => implode(', ', $turn->tool_calls ?? []) ?: null),
                TextColumn::make('duration_ms')->label(__('Duration'))->placeholder('—')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => number_format(((int) $state) / 1000, 1).' s'),
                TextColumn::make('finish_reason')->label(__('Ended'))->placeholder('—')
                    ->formatStateUsing(fn (string $state) => Str::headline($state)),
                TextColumn::make('rating')->label(__('Rating'))->placeholder('—')
                    ->state(fn (AgentTurn $turn) => self::ratingOf($turn)['rating'] ?? null)
                    ->formatStateUsing(fn (string $state) => $state === 'up' ? __('Helpful') : __('Not helpful'))
                    ->badge()
                    ->color(fn (string $state) => $state === 'up' ? 'success' : 'danger')
                    ->tooltip(fn (AgentTurn $turn) => self::ratingOf($turn)['note'] ?? null),
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
                SelectFilter::make('rating')->label(__('Rating'))
                    ->options(['up' => __('Helpful'), 'down' => __('Not helpful')])
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null) ? $query->whereIn('id', AgentMessageFeedback::query()->select('turn_id')->where('rating', $data['value'])->whereNotNull('turn_id')) : $query),
            ])
            ->emptyStateHeading(__('No turns yet'))
            ->emptyStateDescription(__('Turns appear here as soon as someone asks the assistant a question.'));
    }

    /** @see AgentTurn::statusLabel() */
    public static function statusLabel(string $status): string
    {
        return AgentTurn::statusLabel($status);
    }

    /**
     * How the answer of a turn was rated (the newest rating that names the turn), with the person's note.
     *
     * @return array{rating: string, note: ?string}|null
     */
    public static function ratingOf(AgentTurn $turn): ?array
    {
        $feedback = AgentMessageFeedback::query()->where('turn_id', $turn->id)->latest('id')->first(['rating', 'note']);

        return $feedback ? ['rating' => (string) $feedback->rating, 'note' => $feedback->note] : null;
    }
}
