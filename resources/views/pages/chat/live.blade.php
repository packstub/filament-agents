{{-- The live part of the transcript: the question being sent, the running turn's tools and streamed answer, the
     questions waiting their turn. Reads $live. Part of pages/chat.blade.php, included with its variables; publish this
     file alone to change only this part. --}}
{{-- Live areas. The question being sent is drawn client-side the moment it is sent (agent-chat.js) and
     handed over to the persisted transcript on re-render; the answer, the tools and the status come from the
     turn's event stream (or the poll endpoint) while the job produces them. --}}
<div wire:ignore class="flex justify-end empty:hidden">
    <template x-if="sending">
        <div class="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-sm bg-primary-600 px-4 py-2.5 text-sm text-white shadow-sm" x-text="sending.text"></div>
    </template>
</div>
<div wire:ignore class="fi-chat-timeline fi-chat-timeline-live" x-show="turn !== null && live.tools.length > 0" x-cloak>
    <template x-for="(tool, i) in live.tools" :key="i">
        <div class="fi-chat-timeline-row">
            <span class="fi-chat-timeline-call">
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="fi-chat-timeline-icon" />
                <span class="fi-chat-timeline-name" x-text="tool"></span>
                <span class="fi-chat-timeline-result" x-show="i === live.tools.length - 1 && live.status.endsWith('…') && ! live.status.startsWith(@js(__('Writing'))) && ! live.status.startsWith(@js(__('Reasoning')))" x-text="@js(__('running…'))"></span>
            </span>
        </div>
    </template>
</div>
<div wire:ignore class="fi-chat-md max-w-none text-sm text-gray-800 empty:hidden dark:text-gray-200" :class="{ 'fi-chat-streaming': turn !== null }" x-html="live.html" x-show="live.html !== ''"></div>
<div wire:ignore class="flex items-center gap-2 text-xs text-gray-500" x-show="turn !== null" x-cloak role="status" aria-live="polite">
    <x-filament::loading-indicator class="h-4 w-4" />
    <span x-text="live.status || @js(__('Thinking…'))"></span>
</div>

{{-- Questions waiting their turn on the server (kept per conversation): sent next, one at a time; editable until then. --}}
<div class="flex flex-col gap-5 empty:hidden" x-ref="queued" data-turns="{{ json_encode(array_column($live['queued'], 'id')) }}">
    @foreach ($live['queued'] as $queued)
        <div class="flex flex-col items-end gap-1" wire:key="queued-{{ $queued['id'] }}">
            <div class="fi-chat-queued max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-sm px-4 py-2.5 text-sm">{{ $queued['text'] }}</div>
            <div class="flex items-center gap-3 text-xs text-gray-400">
                <span>{{ __('Queued') }}</span>
                <button type="button" class="hover:text-gray-600 hover:underline dark:hover:text-gray-200" x-on:click="editQueued(@js($queued['id']))">{{ __('Edit') }}</button>
                <button type="button" class="hover:text-gray-600 hover:underline dark:hover:text-gray-200" x-on:click="removeQueued(@js($queued['id']))">{{ __('Remove') }}</button>
            </div>
        </div>
    @endforeach
</div>

{{-- The first question waits for the budget: opening the chat tried to start it and the budget said no. It is
     kept as it is and tried again on the next open, so nothing is offered under it but the reason. --}}
@if ($live['deferred']['error'] ?? null)
    <div class="fi-chat-deferred flex items-center justify-end gap-2 text-xs text-gray-500" role="status" wire:key="deferred-{{ $live['deferred']['id'] }}">
        <x-filament::icon icon="heroicon-m-exclamation-circle" class="h-4 w-4 text-warning-500" />
        <span>{{ $live['deferred']['error'] }} {{ __('It will be answered the next time you open this chat.') }}</span>
    </div>
@endif

{{-- Questions on their way to the server (a moment, while an earlier send is in flight). --}}
<div wire:ignore class="flex flex-col gap-5 empty:hidden">
    <template x-for="queued in outbox" :key="queued.id">
        <div class="flex flex-col items-end gap-1">
            <div class="fi-chat-queued max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-sm px-4 py-2.5 text-sm" x-text="queued.text"></div>
            <div class="flex items-center gap-3 text-xs text-gray-400">
                <span>{{ __('Queued') }}</span>
                <button type="button" class="hover:text-gray-600 hover:underline dark:hover:text-gray-200" x-on:click="edit(queued.id)">{{ __('Edit') }}</button>
                <button type="button" class="hover:text-gray-600 hover:underline dark:hover:text-gray-200" x-on:click="remove(queued.id)">{{ __('Remove') }}</button>
            </div>
        </div>
    </template>
</div>
