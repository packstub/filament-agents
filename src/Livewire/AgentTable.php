<?php

namespace Packstub\Agents\Livewire;

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
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Packstub\Agents\AgentsPlugin;
use Packstub\Agents\Filament\Pages\ResourceTable;
use Packstub\Agents\Support\AgentResources;

/**
 * A resource's table for a show-table answer. It IS the resource's table() —
 * same columns, formatting, sorting and row actions, so every role gate the
 * resource declares applies here too — with the agent's filters as the base
 * query. Rows link to the resource's view/edit page like they do on the list
 * page.
 *
 * In an answer it is compact: one header (the caption and the row count), the
 * rows shown whole up to a cap, no search box, filter button, bulk actions
 * or pager — three pending orders are three rows, not a list page in a chat
 * bubble. A result longer than the cap shows its first rows and a link to the
 * full table (the ResourceTable page), where this same component renders with
 * $full: the list page's search, filters and pagination over the same rows.
 *
 * What the component shows is decided on the server when it is mounted (the
 * stored tool result, or the ResourceTable page after normalizing the URL),
 * so the four properties are locked: a Livewire request from the browser
 * cannot swap the resource, flip the full view, change the caption or send
 * filter values that skipped normalization to the resource's filter closures.
 * The ability is still checked on every request, in table().
 */
class AgentTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** How many rows an answer shows when the plugin says nothing (AgentsPlugin::embeddedTable(limit:)). */
    public const int DEFAULT_LIMIT = 25;

    #[Locked]
    public string $resource = '';

    /** @var array<string, mixed> */
    #[Locked]
    public array $filters = [];

    #[Locked]
    public string $title = '';

    /** The whole result with the list page's controls (the ResourceTable page), not the compact table of an answer. */
    #[Locked]
    public bool $full = false;

    /** The row count, read once per request. */
    protected ?int $total = null;

    public function table(Table $table): Table
    {
        $resource = AgentResources::find($this->resource);
        abort_unless($resource::canViewAny(), 403);

        $table = $resource::table($table)->deferLoading(false);

        $page = $resource::hasPage('view') ? 'view' : ($resource::hasPage('edit') ? 'edit' : null);
        $url = $page ? fn (Model $record) => $resource::getUrl($page, ['record' => $record]) : null;

        // Outside the resource's own list page View/Edit do not know where to go; point them (and the row) at the page.
        $actions = collect($table->getRecordActions())->map(function ($action) use ($resource) {
            if ($action instanceof ViewAction && $resource::hasPage('view')) {
                return $action->url(fn (Model $record) => $resource::getUrl('view', ['record' => $record]));
            }
            if ($action instanceof EditAction && $resource::hasPage('edit')) {
                return $action->url(fn (Model $record) => $resource::getUrl('edit', ['record' => $record]));
            }

            return $action;
        })->all();

        $table->recordActions($actions);

        if ($url) {
            $table->recordUrl($url);
        }

        return $this->full ? $this->fullTable($table, $resource) : $this->compactTable($table, $resource);
    }

    /** The table of an answer: every row up to the cap, the caption and the count as its one header, the chrome off. */
    protected function compactTable(Table $table, string $resource): Table
    {
        $limit = $this->limit();
        $total = $this->total();

        $table
            ->query(fn () => AgentResources::apply($this->resource, $resource::getEloquentQuery(), $this->filters)->limit($limit))
            ->paginated(false)
            ->toolbarActions([])
            ->headerActions([])
            ->heading($this->title ?: Str::ucfirst((string) $resource::getPluralModelLabel()))
            ->description($total > $limit
                ? __('The first :shown of :total', ['shown' => number_format($limit), 'total' => number_format($total)])
                : trans_choice('{0} No rows|{1} :count row|[2,*] :count rows', $total, ['count' => number_format($total)]));

        // The chat column is narrower than the list page: columns the resource marks as toggleable start hidden
        // (the column picker brings them back), so the essentials and the row actions fit without scrolling.
        foreach ($table->getColumns() as $column) {
            if ($column->isToggleable()) {
                $column->toggleable(isToggledHiddenByDefault: true);
            }
        }

        // The search box and the filter button start hidden (AgentsPlugin::embeddedTable): the assistant already
        // filtered the table and the answer says what it shows.
        $plugin = AgentsPlugin::current();
        if (! $plugin?->hasEmbeddedTableSearch()) {
            $table->searchable(false);
            foreach ($table->getColumns() as $column) {
                $column->searchable(false);
            }
        }
        if (! $plugin?->hasEmbeddedTableFilters()) {
            $table->filters([]);
        }

        return $table;
    }

    /** The whole result on its own page: the resource's search, filters, columns and bulk actions, ten rows a page. */
    protected function fullTable(Table $table, string $resource): Table
    {
        return $table
            ->query(fn () => AgentResources::apply($this->resource, $resource::getEloquentQuery(), $this->filters))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }

    /** How many rows the agent's filters match now (the data may have moved on since the answer). */
    public function total(): int
    {
        return $this->total ??= AgentResources::apply($this->resource, AgentResources::find($this->resource)::getEloquentQuery(), $this->filters)->count();
    }

    /** How many rows an answer shows before it links to the full table. */
    public function limit(): int
    {
        return max(1, AgentsPlugin::current()?->getEmbeddedTableLimit() ?? self::DEFAULT_LIMIT);
    }

    /** Where the whole result opens, when the answer shows only its first rows; null when they are all here. */
    public function fullUrl(): ?string
    {
        if ($this->full || $this->total() <= $this->limit()) {
            return null;
        }

        return ResourceTable::getUrl(array_filter(['resource' => $this->resource, 'filters' => $this->filters, 'title' => $this->title], fn ($value) => $value !== '' && $value !== []));
    }

    public function render(): View
    {
        return view('packstub-agents::livewire.agent-table');
    }
}
