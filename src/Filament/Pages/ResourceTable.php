<?php

namespace Packstub\Agents\Filament\Pages;

use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Packstub\Agents\Support\AgentModels;
use Packstub\Agents\Support\AgentResources;
use Throwable;

/**
 * The whole of a table the assistant showed: an answer embeds the first rows
 * of a long result and links here, where the same rows — the resource's own
 * table over the assistant's filters — come with the list page's search,
 * filters, pagination and bulk actions. The filters ride in the URL in the
 * assistant's vocabulary and are normalized again on the way in, and the
 * table runs the resource's own query, so the page shows nothing the person
 * could not reach from the list page.
 */
class ResourceTable extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'agent-table/{resource}';

    protected string $view = 'packstub-agents::pages.resource-table';

    public string $resource = '';

    /** @var array<string, mixed> */
    public array $filters = [];

    public string $caption = '';

    public static function canAccess(): bool
    {
        return AgentModels::enabled();
    }

    public function mount(string $resource): void
    {
        abort_unless(AgentResources::has($resource), 404);
        abort_unless(AgentResources::find($resource)::canViewAny(), 403);

        $this->resource = $resource;
        $this->filters = AgentResources::normalizeFilters($resource, (array) request()->query('filters', []));
        $this->caption = Str::limit(trim((string) request()->query('title', '')), 120, '');
    }

    public function getTitle(): string|Htmlable
    {
        return $this->caption !== '' ? $this->caption : $this->resourceLabel();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('A table the assistant put together. Search, filter and page through all of its rows.');
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        $index = $this->resourceUrl();

        return [...($index ? [$index => $this->resourceLabel()] : [$this->resourceLabel()]), (string) $this->getTitle()];
    }

    protected function getHeaderActions(): array
    {
        $index = $this->resourceUrl();

        return $index ? [
            Action::make('resource')
                ->label(__('All :resource', ['resource' => Str::lower($this->resourceLabel())]))
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->url($index),
        ] : [];
    }

    /** A page that was never mounted (Shield reading every page's title for its permissions) has no resource. */
    protected function resourceLabel(): string
    {
        if (! AgentResources::has($this->resource)) {
            return __('Table');
        }

        return Str::ucfirst((string) AgentResources::find($this->resource)::getPluralModelLabel());
    }

    /** The resource's own list page, where it has one. */
    protected function resourceUrl(): ?string
    {
        try {
            return AgentResources::find($this->resource)::getUrl('index');
        } catch (Throwable) {
            return null;
        }
    }
}
