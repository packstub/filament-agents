{{-- An answer in the transcript: the reasoning, then the parts below (lookups, proposals, the text, embeds, sources,
     the footer). Reads $message. Part of pages/chat.blade.php, included with its variables; publish this file alone to
     change only this part. --}}
<div class="fi-chat-exchange flex flex-col gap-2 {{ $message['continued'] ? 'fi-chat-continued' : '' }}" wire:key="message-{{ $message['id'] }}">
    @if ($message['reasoning'] !== '')
        {{-- What the model thought before it answered, when the provider reports it: folded, one line to open it. --}}
        <div class="fi-chat-reasoning" x-data="{ open: false }">
            <button type="button" class="fi-chat-timeline-call" x-on:click="open = ! open" x-bind:aria-expanded="open">
                <x-filament::icon icon="heroicon-m-light-bulb" class="fi-chat-timeline-icon" />
                <span class="fi-chat-timeline-name">{{ __('Reasoning') }}</span>
                <x-filament::icon icon="heroicon-m-chevron-right" class="fi-chat-proposal-chevron" x-bind:class="{ 'fi-chat-proposal-chevron-open': open }" />
            </button>
            <div class="fi-chat-reasoning-text" x-show="open" x-collapse x-cloak>{{ $message['reasoning'] }}</div>
        </div>
    @endif

    @include('packstub-agents::pages.chat.answer.lookups')

    @include('packstub-agents::pages.chat.answer.proposals')

    @if (trim($message['html']) !== '')
        <div class="fi-chat-md max-w-none text-sm text-gray-800 dark:text-gray-200" data-text="{{ $message['text'] }}">{!! $message['html'] !!}</div>
    @endif

    @include('packstub-agents::pages.chat.answer.embeds')

    @if ($message['sources'])
        {{-- The web pages the answer cites (the provider's web search): what came from outside the workspace, and where from. --}}
        <div class="fi-chat-sources">
            <span class="fi-chat-sources-label">{{ __('Sources') }}</span>
            @foreach ($message['sources'] as $source)
                <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer nofollow" class="fi-chat-source" title="{{ $source['url'] }}">
                    <x-filament::icon icon="heroicon-m-globe-alt" class="h-3.5 w-3.5" />
                    <span>{{ \Illuminate\Support\Str::limit($source['title'], 60) }}</span>
                </a>
            @endforeach
        </div>
    @endif

    @include('packstub-agents::pages.chat.answer.footer')
</div>
