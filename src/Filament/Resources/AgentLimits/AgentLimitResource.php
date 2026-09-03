<?php

namespace Packstub\Agents\Filament\Resources\AgentLimits;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Filament\Resources\AgentLimits\Pages\ManageAgentLimits;
use Packstub\Agents\Models\AgentLimit;
use Packstub\Agents\Support\AgentLimits;

/**
 * AI limits (the operator panel): the spending guard rails. One global row
 * applies to everyone; a workspace row overrides it for that workspace; a
 * user row overrides the per-user fields for one account in every
 * workspace. Empty fields inherit.
 */
class AgentLimitResource extends Resource
{
    protected static ?string $model = AgentLimit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return __('AI limits');
    }

    public static function getModelLabel(): string
    {
        return __('AI limit');
    }

    public static function getPluralModelLabel(): string
    {
        return __('AI limits');
    }

    public static function canViewAny(): bool
    {
        return Agents::canManageLimits();
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        $defaults = config('packstub-agents.limits', []);
        $inherit = fn (string $field) => __('inherit (:value)', ['value' => ($defaults[$field] ?? null) ?: '∞']);
        $number = fn (string $field, string $label, string $help) => TextInput::make($field)
            ->label($label)->numeric()->minValue(0)->placeholder($inherit($field))->helperText($help)
            ->dehydrateStateUsing(fn ($state) => $state === '' || $state === null ? null : (int) $state);

        $tenantModel = AgentLimit::tenantModel();
        $scopes = ['global' => __('Everyone (defaults)')] + ($tenantModel ? ['tenant' => __('One workspace')] : []) + ['user' => __('One user')];

        return $schema->components([
            Section::make(__('Who'))
                ->columns(3)
                ->components([
                    Select::make('scope')->label(__('Scope'))->required()->live()->native(false)
                        ->options($scopes)
                        ->disabled(fn (?AgentLimit $record) => $record !== null),
                    Select::make('scope_id')->label(__('Workspace'))->searchable()->required()
                        ->options(fn () => $tenantModel ? $tenantModel::query()->get()->mapWithKeys(fn ($t) => [$t->getKey() => AgentLimit::tenantName($t->getKey()) ?? $t->getKey()])->sort()->all() : [])
                        ->visible(fn ($get) => $get('scope') === 'tenant'),
                    Select::make('scope_id')->label(__('User'))->searchable()->required()
                        ->options(fn () => AgentLimit::userModel()::query()->get()->mapWithKeys(fn ($u) => [$u->getKey() => trim(($u->name ?? '').' <'.($u->email ?? $u->getKey()).'>')])->sort()->all())
                        ->visible(fn ($get) => $get('scope') === 'user'),
                    Select::make('enabled')->label(__('Assistant'))->native(false)
                        ->options(['1' => __('On'), '0' => __('Off')])->placeholder(__('inherit'))
                        ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : (bool) (int) $state)
                        ->formatStateUsing(fn ($state) => $state === null ? null : ($state ? '1' : '0')),
                ]),
            Section::make(__('Limits'))
                ->description(__('Empty means inherit: user → workspace → everyone → the platform defaults from .env.'))
                ->columns(2)
                ->components([
                    $number('turns_per_minute', __('Questions per minute, per user'), __('Burst protection.')),
                    $number('turns_per_day', __('Answers per day, per workspace'), __('Resets at midnight.'))
                        ->visible(fn ($get) => $get('scope') !== 'user'),
                    $number('tokens_per_month', __('Tokens per month, per workspace'), __('All token kinds, as reported by the provider.'))
                        ->visible(fn ($get) => $get('scope') !== 'user'),
                    $number('user_tokens_per_day', __('Tokens per day, per user'), __('Resets at midnight; counted inside each workspace.')),
                    $number('user_tokens_per_month', __('Tokens per month, per user'), __('Counted inside each workspace.')),
                    $number('prompt_max_chars', __('Max question length'), __('Characters.')),
                    TextInput::make('note')->label(__('Note'))->maxLength(255)->placeholder(__('Why this override exists')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $fmt = fn ($state) => $state === null ? '—' : number_format((int) $state);

        return $table
            ->defaultSort('scope')
            ->columns([
                TextColumn::make('scope')->label(__('Scope'))->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'tenant' => __('Workspace'), 'user' => __('User'), default => __('Everyone')
                    })
                    ->color(fn (string $state) => match ($state) {
                        'tenant' => 'info', 'user' => 'warning', default => 'primary'
                    }),
                TextColumn::make('target')->label(__('Applies to'))->state(fn (AgentLimit $r) => $r->targetLabel())->searchable(false),
                TextColumn::make('enabled')->label(__('Assistant'))->formatStateUsing(fn ($state) => $state === null ? '—' : ($state ? __('On') : __('Off')))->placeholder('—'),
                TextColumn::make('turns_per_minute')->label(__('/ min'))->formatStateUsing($fmt)->placeholder('—'),
                TextColumn::make('turns_per_day')->label(__('Answers / day'))->formatStateUsing($fmt)->placeholder('—'),
                TextColumn::make('tokens_per_month')->label(__('Tokens / month'))->formatStateUsing($fmt)->placeholder('—'),
                TextColumn::make('user_tokens_per_day')->label(__('User tokens / day'))->formatStateUsing($fmt)->placeholder('—'),
                TextColumn::make('user_tokens_per_month')->label(__('User tokens / month'))->formatStateUsing($fmt)->placeholder('—'),
                TextColumn::make('prompt_max_chars')->label(__('Max chars'))->formatStateUsing($fmt)->placeholder('—'),
                TextColumn::make('note')->label(__('Note'))->limit(30)->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make()->after(fn () => AgentLimits::flush()),
                DeleteAction::make()->after(fn () => AgentLimits::flush()),
            ])
            ->emptyStateHeading(__('No limits yet'))
            ->emptyStateDescription(__('Until a row exists, the platform defaults from .env apply: :defaults', ['defaults' => collect(config('packstub-agents.limits', []))->only(AgentLimit::FIELDS)->map(fn ($v, $k) => "{$k}={$v}")->join(', ')]));
    }

    public static function getPages(): array
    {
        return ['index' => ManageAgentLimits::route('/')];
    }
}
