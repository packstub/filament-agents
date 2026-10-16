{{-- The chat's header: the title (rename), what the chat is about, pin, export and New chat. Reads $conversation,
     $embedded and the page ($this). Part of pages/chat.blade.php, included with its variables; publish this file alone
     to change only this part. --}}
<div class="fi-chat-header flex items-center justify-between gap-3">
    <div class="min-w-0 flex-1" x-data="{ renaming: false, title: @js((string) $this->getTitle()) }">
        <div class="flex items-center gap-1.5" x-show="! renaming">
            <h1 class="truncate text-xl font-semibold text-gray-950 dark:text-white">{{ $this->getTitle() }}</h1>
            @if ($conversation)
                <button type="button" class="fi-chat-header-tool" title="{{ __('Rename') }}" aria-label="{{ __('Rename') }}" x-on:click="renaming = true; $nextTick(() => $refs.title.select())">
                    <x-filament::icon icon="heroicon-m-pencil" class="h-4 w-4" />
                </button>
            @endif
        </div>
        @if ($conversation)
            <form class="flex items-center gap-2" x-show="renaming" x-cloak x-on:submit.prevent="renaming = false; $wire.rename(title)">
                <input x-ref="title" x-model="title" type="text" maxlength="100" class="fi-chat-rename-input" aria-label="{{ __('Chat title') }}" x-on:keydown.escape.prevent="renaming = false; title = @js((string) $this->getTitle())" />
                <x-filament::button type="submit" size="xs">{{ __('Save') }}</x-filament::button>
                <x-filament::link tag="button" type="button" size="sm" color="gray" :x-on:click="'renaming = false; title = '.\Illuminate\Support\Js::from((string) $this->getTitle())">{{ __('Cancel') }}</x-filament::link>
            </form>
        @endif
        @if ($label = $this->contextLabel())
            {{-- What the chat is about, linked to the record's own page (opened over the slide-over's frame, not inside it). --}}
            <p class="fi-chat-about mt-0.5 text-xs text-gray-500">
                @if ($recordUrl = $this->contextUrl())
                    {!! __('About :record', ['record' => '<a href="'.e($recordUrl).'" target="_top">'.e($label).'</a>']) !!}
                @else
                    {{ __('About :record', ['record' => $label]) }}
                @endif
            </p>
        @endif
    </div>
    <div class="flex shrink-0 items-center gap-1">
        @if ($conversation)
            @php($pinned = $this->pinned())
            <button type="button" wire:click="togglePin" class="fi-chat-header-tool {{ $pinned ? 'fi-chat-header-tool-on' : '' }}" title="{{ $pinned ? __('Unpin') : __('Pin') }}" aria-label="{{ $pinned ? __('Unpin') : __('Pin') }}" aria-pressed="{{ $pinned ? 'true' : 'false' }}">
                <x-filament::icon :icon="$pinned ? 'heroicon-s-bookmark' : 'heroicon-o-bookmark'" class="h-4 w-4" />
            </button>
            <button type="button" wire:click="export" class="fi-chat-header-tool" title="{{ __('Export as Markdown') }}" aria-label="{{ __('Export as Markdown') }}">
                <x-filament::icon icon="heroicon-o-arrow-down-tray" class="h-4 w-4" />
            </button>
        @endif
        @if ($embedded)
            <x-filament::link :href="$this->pageUrl()" target="_top" size="sm" color="gray" icon="heroicon-m-arrow-top-right-on-square">{{ __('Open full page') }}</x-filament::link>
            <x-filament::button tag="a" :href="\Packstub\Agents\Filament\Pages\Chat::getUrl(['embedded' => 1])" size="sm" color="gray" outlined icon="heroicon-m-plus">{{ __('New chat') }}</x-filament::button>
        @else
            <x-filament::link :href="\Packstub\Agents\Filament\Pages\Chats::getUrl()" size="sm" color="gray" class="ms-1">{{ __('All chats') }}</x-filament::link>
            <x-filament::button tag="a" :href="\Packstub\Agents\Filament\Pages\Chat::getUrl()" size="sm" color="gray" outlined icon="heroicon-m-plus" class="ms-1">{{ __('New chat') }}</x-filament::button>
        @endif
    </div>
</div>
