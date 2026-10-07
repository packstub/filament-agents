<?php

namespace Packstub\Agents\Filament\Resources\AgentLimits;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Filament\Resources\AgentLimits\Pages\ManageAgentLimits;
use Packstub\Agents\Models\AgentLimit;
use Packstub\Agents\Support\AgentLimits;

/**
 * AI limits (the operator panel): the spending guard rails. One global row
 * applies to everyone; a workspace row overrides it for that workspace; a
 * user row overrides the per-user fields for one account in every
 * workspace. Empty fields inherit. On a panel with tenancy the resource is
 * the current workspace's row alone (see tenantScope()); the operator
 * console, without tenancy, lists them all.
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
        return self::canViewAny() && self::inScope($record);
    }

    public static function canDelete(Model $record): bool
    {
        return self::canViewAny() && self::inScope($record);
    }

    /**
     * The one row a panel with tenancy may list and edit: the current workspace's. Null on a panel
     * without tenancy (the operator console), where every row is in reach.
     *
     * @return array{scope: string, scope_id: string}|null
     */
    public static function tenantScope(): ?array
    {
        $tenant = Filament::getTenant();

        return $tenant ? ['scope' => 'tenant', 'scope_id' => (string) $tenant->getKey()] : null;
    }

    public static function inScope(Model $record): bool
    {
        $scope = self::tenantScope();

        return $scope === null || ($record->scope === $scope['scope'] && (string) $record->scope_id === $scope['scope_id']);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->when(self::tenantScope(), fn (Builder $query, array $scope) => $query->where($scope));
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
        // On a panel with tenancy the row is the current workspace's: both pickers show it and stay disabled
        // (so they are not saved); the page's actions write the scope server-side.
        $forced = self::tenantScope();

        return $schema->components([
            Section::make(__('Who'))
                ->columns(3)
                ->components([
                    Select::make('scope')->label(__('Scope'))->required()->live()->native(false)
                        ->options($forced ? ['tenant' => __('One workspace')] : $scopes)
                        ->default($forced ? 'tenant' : null)
                        ->disabled(fn (?AgentLimit $record) => $record !== null || $forced !== null),
                    Select::make('scope_id')->label(__('Workspace'))->searchable()->required()
                        ->options(fn () => match (true) {
                            $forced !== null => [$forced['scope_id'] => AgentLimit::tenantName($forced['scope_id']) ?? $forced['scope_id']],
                            $tenantModel !== null => $tenantModel::query()->get()->mapWithKeys(fn ($t) => [$t->getKey() => AgentLimit::tenantName($t->getKey()) ?? $t->getKey()])->sort()->all(),
                            default => [],
                        })
                        ->default($forced['scope_id'] ?? null)
                        ->disabled($forced !== null)
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
                    $number('tokens_per_day', __('Tokens per day, per workspace'), __('Resets at midnight; all token kinds, as reported by the provider.'))
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
                TextColumn::make('turns_per_day')->label(__('Answers / day'))->formatStateUsing($fmt)->placeholder('—'),
                TextColumn::make('tokens_per_day')->label(__('Tokens / day'))->formatStateUsing($fmt)->placeholder('—'),
                TextColumn::make('tokens_per_month')->label(__('Tokens / month'))->formatStateUsing($fmt)->placeholder('—'),
                // The per-user detail fits a laptop screen only when toggled in.
                TextColumn::make('turns_per_minute')->label(__('/ min'))->formatStateUsing($fmt)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user_tokens_per_day')->label(__('User tokens / day'))->formatStateUsing($fmt)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user_tokens_per_month')->label(__('User tokens / month'))->formatStateUsing($fmt)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('prompt_max_chars')->label(__('Max chars'))->formatStateUsing($fmt)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('note')->label(__('Note'))->limit(40)->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data) => self::forceScope($data))->after(fn () => AgentLimits::flush()),
                DeleteAction::make()->after(fn () => AgentLimits::flush()),
            ])
            ->emptyStateHeading(__('No limits yet'))
            ->emptyStateDescription(__('Until a row exists, the platform defaults from .env apply: :defaults', ['defaults' => collect(config('packstub-agents.limits', []))->only(AgentLimit::FIELDS)->map(fn ($v, $k) => "{$k}={$v}")->join(', ')]));
    }

    /** What a create or edit saves, with the workspace's scope written over whatever the form sent on a panel with tenancy. */
    public static function forceScope(array $data): array
    {
        return array_merge($data, self::tenantScope() ?? []);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAgentLimits::route('/')];
    }
}
