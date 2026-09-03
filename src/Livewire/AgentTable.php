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
use Livewire\Component;
use Packstub\Agents\Support\AgentResources;

/**
 * A resource's table, embedded in a chat answer (show_table). It IS the
 * resource's table() — same columns, filters, sorting and row actions, so
 * every role gate the resource declares applies here too — with the agent's
 * filters as the base query and a smaller page size. Rows link to the
 * resource's view/edit page like they do on the list page.
 */
class AgentTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public string $resource = '';

    /** @var array<string, mixed> */
    public array $filters = [];

    public string $title = '';

    public function table(Table $table): Table
    {
        $resource = AgentResources::find($this->resource);
        abort_unless($resource::canViewAny(), 403);

        $table = $resource::table($table)
            ->query(fn () => AgentResources::apply($this->resource, $resource::getEloquentQuery(), $this->filters))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->extremePaginationLinks(false)
            ->deferLoading(false);

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

        // The chat column is narrower than the list page: columns the resource marks as toggleable start hidden
        // (the column picker brings them back), so the essentials and the row actions fit without scrolling.
        foreach ($table->getColumns() as $column) {
            if ($column->isToggleable()) {
                $column->toggleable(isToggledHiddenByDefault: true);
            }
        }

        $table->recordActions($actions)->heading($this->title ?: null);

        if ($url) {
            $table->recordUrl($url);
        }

        return $table;
    }

    public function render(): View
    {
        return view('packstub-agents::livewire.agent-table');
    }
}
