<?php

namespace Packstub\Agents\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;
use Packstub\Agents\Support\AgentResources;

/**
 * A resource's table, embedded in a chat answer (show-table). It IS the
 * resource's table() — same columns, sorting and row actions, so every role
 * gate the resource declares applies here too — with the agent's filters as
 * the base query. A result that fits on one screen (up to COMPACT_ROWS) is
 * shown whole, without the search field, the filters and the pagination of
 * the list page; a longer one keeps them, ten rows a page. Rows link to the
 * resource's view/edit page like they do on the list page.
 */
class AgentTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** Up to this many rows the table is shown whole, without toolbar and pagination. */
    public const COMPACT_ROWS = 10;

    public string $resource = '';

    /** @var array<string, mixed> */
    public array $filters = [];

    public string $title = '';

    protected ?int $count = null;

    public function table(Table $table): Table
    {
        $resource = AgentResources::find($this->resource);
        abort_unless($resource::canViewAny(), 403);

        $table = $resource::table($table)
            ->query(fn () => AgentResources::apply($this->resource, $resource::getEloquentQuery(), $this->filters))
            ->deferLoading(false);

        if ($this->count() <= self::COMPACT_ROWS) {
            $table->paginated(false)->searchable(false)->filters([]);
        } else {
            $table->paginated([10, 25])->defaultPaginationPageOption(10)->extremePaginationLinks(false);
        }

        $page = $resource::hasPage('view') ? 'view' : ($resource::hasPage('edit') ? 'edit' : null);
        $url = $page ? fn (Model $record) => $resource::getUrl($page, ['record' => $record]) : null;

        // Outside the resource's own list page View/Edit do not know where to go; point them (and the row) at the page.
        // Row actions are plain links without icons, so the essentials fit the chat column.
        $actions = collect($table->getRecordActions())->map(function ($action) use ($resource) {
            if ($action instanceof ViewAction && $resource::hasPage('view')) {
                $action->url(fn (Model $record) => $resource::getUrl('view', ['record' => $record]));
            }
            if ($action instanceof EditAction && $resource::hasPage('edit')) {
                $action->url(fn (Model $record) => $resource::getUrl('edit', ['record' => $record]));
            }
            if ($action instanceof Action) {
                $action->link()->icon(null);
            }

            return $action;
        })->all();

        // The chat column is narrower than the list page: columns the resource marks as toggleable start hidden
        // (the column manager brings them back), so the essentials and the row actions fit without scrolling.
        foreach ($table->getColumns() as $column) {
            if ($column->isToggleable()) {
                $column->toggleable(isToggledHiddenByDefault: true);
            }
        }

        $table->recordActions($actions)->heading(null);

        if ($url) {
            $table->recordUrl($url);
        }

        return $table;
    }

    /** How many rows the agent's filters match. */
    public function count(): int
    {
        $resource = AgentResources::find($this->resource);

        return $this->count ??= AgentResources::apply($this->resource, $resource::getEloquentQuery(), $this->filters)->count();
    }

    /** The resource's plural label: the caption when the agent gave none. */
    public function label(): string
    {
        return AgentResources::find($this->resource)::getPluralModelLabel();
    }

    public function render(): View
    {
        return view('packstub-agents::livewire.agent-table');
    }
}
