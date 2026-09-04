<div class="fi-chat-table">
    <div class="fi-chat-table-header">
        <span class="fi-chat-table-title">{{ $title ?: $this->label() }}</span>
        <span class="fi-chat-table-meta">{{ trans_choice(':count row · live table|:count rows · live table', $this->count(), ['count' => $this->count()]) }}</span>
    </div>
    {{ $this->table }}
</div>
