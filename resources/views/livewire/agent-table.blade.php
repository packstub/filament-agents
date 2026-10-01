<div @class(['fi-chat-table', 'fi-chat-table-compact' => ! $full])>
    {{ $this->table }}

    @if ($more = $this->fullUrl())
        {{-- A long result: its first rows are above; the whole of it opens with the list page's search, filters and pagination. --}}
        <a href="{{ $more }}" target="_top" class="fi-chat-table-more">
            <span>{{ __('Open all :count in a full table', ['count' => number_format($this->total())]) }}</span>
            <x-filament::icon icon="heroicon-m-arrow-right" class="h-4 w-4" />
        </a>
    @endif
</div>
