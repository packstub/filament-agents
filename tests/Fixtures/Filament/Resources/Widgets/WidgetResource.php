<?php

namespace Packstub\Agents\Tests\Fixtures\Filament\Resources\Widgets;

use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Packstub\Agents\Concerns\InteractsWithAgent;
use Packstub\Agents\Contracts\AgentResource;
use Packstub\Agents\Filters\Filter;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Filament\Resources\Widgets\Pages\EditWidget;
use Packstub\Agents\Tests\Fixtures\Filament\Resources\Widgets\Pages\ListWidgets;
use Packstub\Agents\Tests\Fixtures\Models\Widget;
use Packstub\Agents\Tests\Fixtures\Models\WidgetStatus;

class WidgetResource extends Resource implements AgentResource
{
    use InteractsWithAgent;

    protected static ?string $model = Widget::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return Abilities::allows('widgets.view');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            Select::make('status')->options(collect(WidgetStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->name])->all()),
            TextInput::make('price')->numeric(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('price')->toggleable(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWidgets::route('/'),
            'edit' => EditWidget::route('/{record}/edit'),
        ];
    }

    public static function agentSummary(Model $record, bool $full = false): array
    {
        return [
            'id' => $record->id,
            'name' => $record->name,
            'status' => $record->status,
            'price' => (float) $record->price,
            'url' => static::agentRecordUrl($record),
        ];
    }

    public static function agentContextLabel(Model $record): string
    {
        return 'Widget '.$record->name;
    }

    public static function agentFilters(): array
    {
        return [
            Filter::text('query')->description('Part of the name.')
                ->apply(fn (Builder $q, string $text) => $q->where('name', 'like', "%{$text}%")),
            Filter::enum('status', WidgetStatus::class)->multiple()
                ->apply(fn (Builder $q, array $status) => $q->whereIn('status', $status)),
            Filter::flag('live_only')->description('Only live widgets.')
                ->apply(fn (Builder $q) => $q->where('status', WidgetStatus::Live->value)),
            Filter::number('min_price')
                ->apply(fn (Builder $q, int|float $min) => $q->where('price', '>=', $min)),
            Filter::date('created_from')
                ->apply(fn (Builder $q, string $date) => $q->where('created_at', '>=', $date)),
        ];
    }
}
