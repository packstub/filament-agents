<x-filament-panels::page>
    @php
        $live = $this->live();
        $context = $this->history();
        $messages = $this->messages();
        $lastQuestionId = $messages->last(fn ($m) => $m['role'] === 'user' && ! $m['continuation'])['id'] ?? null;
        $lastQuestionVersions = $lastQuestionId ? $messages->firstWhere('id', $lastQuestionId)['versions'] : 0;
    @endphp
    <div
        class="fi-chat mx-auto flex w-full max-w-3xl flex-col gap-6 {{ $embedded ? 'fi-chat-embedded' : '' }}"
        x-load
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('agent-chat', 'packstub/filament-agents') }}"
        x-data="agentChat()"
    >
        {{-- What the component reads per render. Kept out of x-data on purpose: a changed x-data expression makes the
             morph re-initialise the component, which would drop the outbox and the polling state. --}}
        <div
            x-ref="state"
            hidden
            data-prompt="{{ $prompt }}"
            data-auto-send="{{ $autoSend ? '1' : '' }}"
            data-poll="{{ $this->pollUrl() }}"
            data-stream="{{ $this->streamUrl() }}"
            data-active="{{ $live['active']['id'] ?? '' }}"
            data-interval="{{ \Packstub\Agents\Support\AgentTurns::pollInterval() }}"
            data-title="{{ $this->getTitle() }}"
            data-conversation="{{ $conversation ?? '' }}"
            data-suggestions="{{ json_encode($this->suggestions()) }}"
            data-mentions="{{ $this->canMention() ? '1' : '' }}"
            data-copied="{{ __('Copied') }}"
            data-copy="{{ __('Copy') }}"
        ></div>

        @include('packstub-agents::pages.chat.header')

        @include('packstub-agents::pages.chat.suggestions')

        <div class="flex flex-col gap-5" x-ref="transcript" role="log" aria-live="polite" aria-relevant="additions text" aria-label="{{ __('Conversation') }}">
            @if ($context && $context['source'])
                <p class="text-xs text-gray-500">
                    {{ __('Continued from') }}
                    <a href="{{ \Packstub\Agents\Filament\Pages\Chat::getUrl(['conversation' => $context['source']]) }}" class="font-medium text-primary-600 hover:underline">{{ $context['sourceTitle'] ?? __('an earlier chat') }}</a>
                    — {{ __('the assistant starts from a summary of it.') }}
                </p>
            @endif

            @foreach ($messages as $message)
                @if ($message['role'] === 'user')
                    @include('packstub-agents::pages.chat.question')
                @else
                    @include('packstub-agents::pages.chat.answer')
                @endif
            @endforeach

            @include('packstub-agents::pages.chat.live')
        </div>

        @include('packstub-agents::pages.chat.composer')
    </div>
</x-filament-panels::page>
