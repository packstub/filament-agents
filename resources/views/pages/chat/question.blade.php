{{-- A question in the transcript, with its files and mentions, Edit on the last one, and how it ended when it got no
     answer. Reads $message and $live. Part of pages/chat.blade.php, included with its variables; publish this file
     alone to change only this part. --}}
@unless ($message['continuation'])
    <div class="fi-chat-exchange flex items-end justify-end gap-2" wire:key="message-{{ $message['id'] }}">
        @if ($message['editable'])
            {{-- The last question: edit it and send it again (the answer is kept as a version). --}}
            <button type="button" class="fi-chat-exchange-tools rounded p-1 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200" title="{{ __('Edit and send again') }}" aria-label="{{ __('Edit and send again') }}" x-show="! busy" x-on:click="editLast(@js($message['text']))">
                <x-filament::icon icon="heroicon-m-pencil-square" class="h-4 w-4" />
            </button>
        @endif
        <div class="fi-chat-question max-w-[85%]">
            @if ($message['attachments'])
                <div class="fi-chat-attachments">
                    @foreach ($message['attachments'] as $attachment)
                        @if ($attachment['image'] && $attachment['url'])
                            <a href="{{ $attachment['url'] }}" target="_blank" rel="noopener" class="fi-chat-attachment-image" title="{{ $attachment['name'] }}"><img src="{{ $attachment['url'] }}" alt="{{ $attachment['name'] }}" /></a>
                        @else
                            <a @if ($attachment['url']) href="{{ $attachment['url'] }}" target="_blank" rel="noopener" @endif class="fi-chat-attachment-file">
                                <x-filament::icon icon="heroicon-m-paper-clip" class="h-3.5 w-3.5" />
                                <span>{{ $attachment['name'] }}</span>
                            </a>
                        @endif
                    @endforeach
                </div>
            @endif
            <div class="whitespace-pre-wrap rounded-2xl rounded-br-sm bg-primary-600 px-4 py-2.5 text-sm text-white shadow-sm">{!! $message['html'] !!}</div>
            @if ($message['mentions'])
                <div class="fi-chat-mentions">
                    @foreach ($message['mentions'] as $mention)
                        <span class="fi-chat-mention" title="{{ $mention['ref'] }}">@ {{ $mention['label'] }}</span>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endunless
@if ($message['unanswered'])
    {{-- Recorded but never answered (refused by a middleware, the provider failed, or the person stopped it): say how
         its turn ended and offer to send it again, under the last question and under any earlier one the chat moved
         on from. Retry on an earlier one moves it to the end of the chat, where its answer lands. --}}
    @php($ended = $message['ended'] ?? ($loop->last ? $live['ended'] : null))
    <div class="fi-chat-unanswered flex items-center justify-end gap-2 text-xs text-gray-500" x-show="! busy" role="status" wire:key="unanswered-{{ $message['id'] }}">
        <x-filament::icon icon="heroicon-m-exclamation-circle" class="h-4 w-4 {{ ($ended['reason'] ?? null) === 'refused' ? 'text-warning-500' : 'text-danger-500' }}" />
        <span>
            @if (($ended['status'] ?? null) === \Packstub\Agents\Models\AgentTurn::STOPPED)
                {{ __('Stopped before an answer.') }}
            @elseif (($ended['reason'] ?? null) === 'refused')
                {{ $ended['error'] }}
            @elseif (($ended['status'] ?? null) === \Packstub\Agents\Models\AgentTurn::FAILED)
                {{ __('The assistant could not answer.') }} {{ $ended['error'] }}
            @else
                {{ __('The assistant did not answer.') }}
            @endif
        </span>
        {{-- A bound attribute: a Blade expression inside a component tag's plain attribute is not compiled. --}}
        <x-filament::link tag="button" size="sm" icon="heroicon-m-arrow-path" :x-on:click="'retry('.\Illuminate\Support\Js::from($message['id']).')'">{{ __('Retry') }}</x-filament::link>
    </div>
@endif
