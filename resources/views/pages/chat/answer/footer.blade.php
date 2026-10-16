{{-- The row under an answer: copy, rating, regenerate, the version switcher, the time, the notes on how it ended, the
     rating note. Reads $message, $lastQuestionId and $lastQuestionVersions. Part of pages/chat.blade.php, included
     with its variables; publish this file alone to change only this part. --}}
@if (trim($message['html']) !== '' || $message['stopped'] || $message['failed'] || $message['posted'])
    {{-- The controls under an answer show when it is hovered or focused (always on a touch screen); a rating
         that was given stays visible, and so do the notes on how the answer ended. --}}
    <div class="fi-chat-answer-footer flex flex-wrap items-center gap-1 text-gray-400" x-data="{ note: false }">
        <button type="button" class="fi-chat-answer-tool rounded p-1 hover:text-gray-700 dark:hover:text-gray-200" title="{{ __('Copy') }}" aria-label="{{ __('Copy answer') }}" x-on:click="copy($event.currentTarget, @js($message['text']))">
            <x-filament::icon icon="heroicon-m-clipboard-document" class="h-4 w-4" />
        </button>
        <button type="button" wire:click="feedback('{{ $message['id'] }}', 'up')" class="rounded p-1 hover:text-success-600 {{ $message['rating'] === 'up' ? 'fi-chat-rated text-success-600' : 'fi-chat-answer-tool' }}" title="{{ __('Helpful') }}" aria-label="{{ __('Helpful') }}" aria-pressed="{{ $message['rating'] === 'up' ? 'true' : 'false' }}">
            <x-filament::icon icon="heroicon-m-hand-thumb-up" class="h-4 w-4" />
        </button>
        <button type="button" x-on:click="note = ! note; if (note) $nextTick(() => $refs.note.focus())" class="rounded p-1 hover:text-danger-600 {{ $message['rating'] === 'down' ? 'fi-chat-rated text-danger-600' : 'fi-chat-answer-tool' }}" title="{{ __('Not helpful') }}" aria-label="{{ __('Not helpful') }}" aria-pressed="{{ $message['rating'] === 'down' ? 'true' : 'false' }}">
            <x-filament::icon icon="heroicon-m-hand-thumb-down" class="h-4 w-4" />
        </button>
        @if ($message['regenerable'])
            {{-- The last answer: produce it again. --}}
            <button type="button" class="fi-chat-answer-tool rounded p-1 hover:text-gray-700 dark:hover:text-gray-200" title="{{ __('Regenerate') }}" aria-label="{{ __('Regenerate') }}" x-show="! busy" x-on:click="regenerate()">
                <x-filament::icon icon="heroicon-m-arrow-path" class="h-4 w-4" />
            </button>
        @endif
        @if ($message['continuable'])
            <x-filament::link tag="button" size="sm" icon="heroicon-m-forward" class="ms-1" x-show="! busy" x-on:click="continueAnswer()">{{ __('Continue') }}</x-filament::link>
        @endif
        @if ($message['regenerable'] && $lastQuestionId && $lastQuestionVersions > 0)
            {{-- Earlier answers to this question: page through them, the current one being the newest. --}}
            @php($versions = $this->versions($lastQuestionId))
            <x-filament::dropdown placement="bottom-start" width="sm">
                <x-slot name="trigger">
                    <button type="button" class="fi-chat-versions" x-show="! busy" aria-label="{{ __('Earlier answers') }}">
                        <x-filament::icon icon="heroicon-m-chevron-left" class="h-3.5 w-3.5" />
                        <span>{{ $lastQuestionVersions + 1 }}/{{ $lastQuestionVersions + 1 }}</span>
                        <x-filament::icon icon="heroicon-m-chevron-right" class="h-3.5 w-3.5" />
                    </button>
                </x-slot>
                <x-filament::dropdown.list>
                    @foreach ($versions as $n => $version)
                        <x-filament::dropdown.list.item wire:click="showVersion('{{ $lastQuestionId }}', {{ $version['id'] }})" x-on:click="close()" icon="heroicon-m-clock">
                            <span class="fi-chat-version-item">
                                <span class="fi-chat-version-label">{{ __('Answer :n', ['n' => $n + 1]) }} · {{ $version['at']?->format('H:i') }}</span>
                                <span class="fi-chat-version-snippet">{{ \Illuminate\Support\Str::limit($version['text'], 80) }}</span>
                            </span>
                        </x-filament::dropdown.list.item>
                    @endforeach
                    <x-filament::dropdown.list.item icon="heroicon-m-check" :disabled="true">
                        <span class="fi-chat-version-item"><span class="fi-chat-version-label">{{ __('Answer :n', ['n' => $lastQuestionVersions + 1]) }} · {{ __('current') }}</span></span>
                    </x-filament::dropdown.list.item>
                </x-filament::dropdown.list>
            </x-filament::dropdown>
        @endif
        <span class="fi-chat-answer-tool ml-1 text-xs">{{ $message['at']?->format('H:i') }}</span>
        @if ($message['stopped'])
            <span class="ml-1 text-xs">· {{ __('(stopped)') }}</span>
        @endif
        @if ($message['posted'])
            {{-- Written by the app as the assistant (a digest, a reminder), not by the model: nothing to produce again. --}}
            <span class="ml-1 text-xs" title="{{ __('Posted by :name, not written by the model.', ['name' => \Packstub\Agents\Facades\Agents::name()]) }}">· {{ __('(posted)') }}</span>
        @endif
        @if ($message['failed'])
            {{-- The provider gave up midway: what arrived is kept, the error says why; Regenerate produces the answer again. --}}
            <span class="ml-1 text-xs text-danger-600 dark:text-danger-400" title="{{ $message['error'] }}">· {{ __('(interrupted)') }}</span>
        @endif
        @if ($message['cutShort'])
            {{-- The provider ended the answer early; Regenerate (above, on the last answer) produces it again, Continue carries on. --}}
            <span class="ml-1 text-xs" title="{{ \Packstub\Agents\Support\AgentChat::cutShortText($message['cutShort']) }}">· {{ __('(cut short)') }}</span>
        @endif
        @if ($message['answeredBy'])
            {{-- The first choice refused the turn and a failover provider took it. --}}
            <span class="ml-1 text-xs" title="{{ __('The usual provider was unavailable; this answer came from :model.', ['model' => $message['answeredBy']['model']]) }}">· {{ __('(answered by :provider)', ['provider' => \Packstub\Agents\Support\AgentModels::providerLabel($message['answeredBy']['provider'])]) }}</span>
        @endif
        @if ($message['ratingNote'])
            <span class="ml-1 text-xs italic" title="{{ __('Your note') }}">· “{{ \Illuminate\Support\Str::limit($message['ratingNote'], 60) }}”</span>
        @endif
        {{-- A thumbs-down may say what went wrong; the rating is saved with or without the note. --}}
        <form class="fi-chat-note basis-full" x-show="note" x-cloak x-on:submit.prevent="$wire.feedback(@js($message['id']), 'down', $refs.note.value); note = false">
            <input x-ref="note" type="text" maxlength="1000" class="fi-chat-note-input" placeholder="{{ __('What went wrong? (optional)') }}" aria-label="{{ __('What went wrong?') }}" x-on:keydown.escape.prevent="note = false" />
            <x-filament::button type="submit" size="xs" color="gray">{{ __('Send') }}</x-filament::button>
        </form>
    </div>
@endif
