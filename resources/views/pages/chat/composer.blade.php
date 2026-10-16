{{-- The bottom of the page: Jump to latest and the composer with the context ring. Reads $context, $model,
     $attachments and the page ($this); the composer itself is the packstub-agents::composer component. Part of
     pages/chat.blade.php, included with its variables; publish this file alone to change only this part. --}}
<div wire:ignore>
    <button
        type="button"
        x-show="busy && ! atBottom"
        x-cloak
        x-transition.opacity
        x-on:click="scrollToBottom(true)"
        class="fi-chat-jump fixed bottom-36 left-1/2 z-20 flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-gray-900 px-3 py-1.5 text-xs font-medium text-white shadow-lg dark:bg-white dark:text-gray-900"
    >
        <x-filament::icon icon="heroicon-m-arrow-down" class="h-3.5 w-3.5" />
        {{ __('Jump to latest') }}
    </button>
</div>

<x-packstub-agents::composer method="send" :model="$model" :attachments="$this->attachmentSettings()" :files="$attachments" :mentions="$this->canMention()" class="sticky bottom-4 shadow-lg">
    @if ($context && $context['meter'])
        {{-- The context ring: how full the history window is, from history.meter_share on. Click for what fills it, what the chat cost so far, Compress now and Continue in a new chat. --}}
        <x-slot:tools>
            @php($percent = (int) round($context['share'] * 100))
            <x-filament::dropdown placement="top-end" width="sm" shift>
                <x-slot name="trigger">
                    <button
                        type="button"
                        class="fi-chat-ring"
                        aria-label="{{ __('Context :percent%', ['percent' => $percent]) }}"
                        x-tooltip="{ content: @js(__('Context :percent%', ['percent' => $percent]).' · '.__('~:used of :budget tokens', ['used' => number_format($context['tokens']), 'budget' => number_format($context['budget'])])), theme: $store.theme, touch: false }"
                    >
                        <svg viewBox="0 0 20 20" aria-hidden="true">
                            <circle class="fi-chat-ring-track" cx="10" cy="10" r="8" pathLength="100" />
                            <circle class="fi-chat-ring-fill {{ $context['notice'] ? 'fi-chat-ring-fill-high' : '' }}" cx="10" cy="10" r="8" pathLength="100" stroke-dasharray="{{ $percent }} 100" />
                        </svg>
                    </button>
                </x-slot>

                <div class="fi-chat-ring-panel flex flex-col gap-4 p-4 text-xs text-gray-600 dark:text-gray-400">
                    <div>
                        <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('History window') }}</p>
                        <p class="mt-0.5">{{ __('~:used of :budget tokens', ['used' => number_format($context['tokens']), 'budget' => number_format($context['budget'])]) }} · {{ __('estimated') }}</p>
                        @if ($context['turns']['last_tokens_in'] !== null)
                            <p class="mt-0.5">{{ __('The last question read :tokens tokens.', ['tokens' => number_format($context['turns']['last_tokens_in'])]) }}</p>
                        @endif
                        <dl class="mt-2">
                            @foreach (\Packstub\Agents\Support\AgentChat::breakdownLabels() as $key => $label)
                                @if ($context['breakdown'][$key] > 0)
                                    <dt>{{ $label }}</dt>
                                    <dd>{{ number_format($context['breakdown'][$key]) }}</dd>
                                @endif
                            @endforeach
                        </dl>
                    </div>

                    @if ($context['turns']['last_tokens_in'] !== null)
                        <div>
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('This chat so far') }}</p>
                            <dl class="mt-2">
                                <dt>{{ __('Turns') }}</dt>
                                <dd>{{ number_format($context['turns']['count']) }}</dd>
                                <dt>{{ __('Tokens in') }}</dt>
                                <dd>{{ number_format($context['turns']['tokens_in']) }}</dd>
                                <dt>{{ __('Tokens out') }}</dt>
                                <dd>{{ number_format($context['turns']['tokens_out']) }}</dd>
                                <dt>{{ __('Tool calls') }}</dt>
                                <dd>{{ number_format($context['turns']['tool_calls']) }}</dd>
                                <dt>{{ __('Time') }}</dt>
                                <dd>{{ \Packstub\Agents\Support\AgentChat::duration($context['turns']['duration_ms']) }}</dd>
                                @if ($context['turns']['cost'] !== null)
                                    <dt>{{ __('Cost') }}</dt>
                                    <dd>{{ \Packstub\Agents\Support\AgentPricing::format($context['turns']['cost']) }}</dd>
                                @endif
                            </dl>
                        </div>
                    @endif

                    @if ($context['source'])
                        <p>
                            {{ __('Continued from') }}
                            <a href="{{ \Packstub\Agents\Filament\Pages\Chat::getUrl(['conversation' => $context['source']]) }}" class="font-medium text-primary-600 hover:underline">{{ $context['sourceTitle'] ?? __('an earlier chat') }}</a>
                            — {{ __('the assistant starts from a summary of it.') }}
                        </p>
                    @elseif ($context['summarized'])
                        <p>{{ __('Older messages are summarized for the assistant.') }}</p>
                    @endif

                    @if ($context['notice'])
                        <p class="text-gray-800 dark:text-gray-200">{{ __('This chat is getting long — answers stay sharpest in a new one.') }}</p>
                    @endif

                    <div class="fi-chat-ring-actions">
                        <x-filament::button size="xs" color="gray" outlined icon="heroicon-m-arrows-pointing-in" wire:click="compressNow" x-bind:disabled="busy">{{ __('Compress now') }}</x-filament::button>
                        <x-filament::button size="xs" color="gray" outlined icon="heroicon-m-arrow-right-circle" wire:click="continueInNewChat" x-bind:disabled="busy">{{ __('Continue in a new chat') }}</x-filament::button>
                    </div>
                </div>
            </x-filament::dropdown>
        </x-slot>
    @endif
</x-packstub-agents::composer>
