<?php

namespace Packstub\FilamentAgents\Support;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Packstub\Agents\Support\AgentResources;
use Packstub\FilamentAgents\AgentsPlugin;
use Packstub\FilamentAgents\FilamentContext;
use Packstub\FilamentAgents\Livewire\AgentTable;
use Packstub\FilamentAgents\Pages\ResourceTable;
use Stringable;
use Throwable;

/**
 * A show-table answer on a page outside the panel (<x-agents-chat /> in a view of the app's own), where the
 * resource's Filament table cannot run: the same rows, read-only. The rows are the resource's own query with the
 * agent's filters, in the table's default order, read inside the assistant's panel and the person's workspace
 * (FilamentContext::enter, as a worker does), so the page shows nothing the panel would not. The cells are what
 * the model sees of a record (agentSummary), the first one linked to the record's page.
 */
class PlainTable
{
    /**
     * @param  array{resource: string, filters?: array<string, mixed>, title?: string, limit?: int}  $table
     * @return array{title: string, note: string, columns: list<string>, rows: list<list<array{text: string, url: ?string}>>, more: ?array{url: string, label: string}}|null
     */
    public static function for(array $table): ?array
    {
        $context = app(FilamentContext::class);
        $panel = $context->panel();

        // A panel with workspaces and no workspace on this page: nothing says whose rows these would be.
        if (! $panel || ! auth()->check() || ($panel->hasTenancy() && ! Filament::getTenant())) {
            return null;
        }

        try {
            $leave = $context->enter(['panel' => $panel->getId()] + $context->capture());
        } catch (Throwable) {
            return null;
        }

        try {
            $key = (string) $table['resource'];
            if (! AgentResources::has($key) || ! ($resource = AgentResources::find($key))::canViewAny()) {
                return null;
            }

            $filters = (array) ($table['filters'] ?? []);
            $cap = max(1, AgentsPlugin::current()?->getEmbeddedTableLimit() ?? AgentTable::DEFAULT_LIMIT);
            $shown = isset($table['limit']) ? max(1, min($cap, (int) $table['limit'])) : $cap;

            $total = AgentResources::apply($key, $resource::getEloquentQuery(), $filters)->count();
            $records = self::sorted($resource, AgentResources::apply($key, $resource::getEloquentQuery(), $filters))->limit($shown)->get();

            $columns = [];
            $rows = [];
            foreach ($records as $record) {
                $summary = $resource::agentSummary($record);
                $url = is_string($summary['url'] ?? null) ? $summary['url'] : null;
                unset($summary['url']);
                if (count($summary) > 1) {
                    unset($summary['id']);
                }

                $columns = $columns ?: array_map(fn (string $name) => Str::ucfirst(str_replace('_', ' ', $name)), array_keys($summary));
                $cells = array_map(fn (mixed $value) => ['text' => self::text($value), 'url' => null], array_values($summary));
                if ($cells !== []) {
                    $cells[0]['url'] = $url;
                }
                $rows[] = $cells;
            }

            $title = (string) ($table['title'] ?? '');

            return [
                'title' => $title !== '' ? $title : Str::ucfirst((string) $resource::getPluralModelLabel()),
                'note' => $total > $shown
                    ? __('The first :shown of :total', ['shown' => number_format($shown), 'total' => number_format($total)])
                    : trans_choice('{0} No rows|{1} :count row|[2,*] :count rows', $total, ['count' => number_format($total)]),
                'columns' => $columns,
                'rows' => $rows,
                'more' => $total > $shown ? self::more($key, $filters, $title, $total) : null,
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        } finally {
            $leave();
        }
    }

    /** The table's own default order (what the panel's table opens in), so "the first two" are the same two. */
    protected static function sorted(string $resource, Builder $query): Builder
    {
        try {
            $table = $resource::table(Table::make(new AgentTable));
            $direction = $table->getDefaultSortDirection() === 'desc' ? 'desc' : 'asc';
            $sort = $table->getDefaultSort($query, $direction);

            if ($sort instanceof Builder) {
                $query = $sort;
            } elseif (is_string($sort)) {
                ($column = $table->getSortableVisibleColumn($sort)) ? $column->applySort($query, $direction) : $query->orderBy($sort, $direction);
            }
        } catch (Throwable) {
            // A table that cannot be read outside its page keeps the query's own order.
        }

        return $query->orderBy($query->getModel()->getQualifiedKeyName());
    }

    /**
     * Where all of the rows open: the panel's full-table page, for someone who can reach it.
     *
     * @param  array<string, mixed>  $filters
     * @return array{url: string, label: string}|null
     */
    protected static function more(string $key, array $filters, string $title, int $total): ?array
    {
        try {
            if (! ResourceTable::canAccess()) {
                return null;
            }

            return [
                'url' => ResourceTable::getUrl(array_filter(['resource' => $key, 'filters' => $filters, 'title' => $title], fn ($value) => $value !== '' && $value !== [])),
                'label' => __('Open all :count in a full table', ['count' => number_format($total)]),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    protected static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? __('Yes') : __('No'),
            $value instanceof BackedEnum => (string) $value->value,
            is_scalar($value), $value instanceof Stringable => (string) $value,
            $value instanceof Model => (string) $value->getKey(),
            is_array($value) => implode(', ', array_map(fn (mixed $item) => self::text($item), $value)),
            default => '',
        };
    }
}
