<x-filament-panels::page>
    <div
        class="fi-chat mx-auto flex w-full max-w-3xl flex-col gap-6"
        x-load
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('agent-chat', 'packstub/filament-agents') }}"
        x-data="agentChat({ prompt: @js($prompt), autoSend: @js($autoSend) })"
    >
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <h1 class="truncate text-xl font-semibold text-gray-950 dark:text-white">{{ $this->getTitle() }}</h1>
                @if ($label = $this->contextLabel())
                    <p class="mt-0.5 text-xs text-gray-500">{{ __('About :record', ['record' => $label]) }}</p>
                @endif
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <x-filament::link :href="\Packstub\Agents\Filament\Pages\Chats::getUrl()" size="sm" color="gray">{{ __('All chats') }}</x-filament::link>
                <x-filament::button tag="a" :href="\Packstub\Agents\Filament\Pages\Chat::getUrl()" size="sm" color="gray" outlined icon="heroicon-m-plus">{{ __('New chat') }}</x-filament::button>
            </div>
        </div>

        @php($context = $this->history())

        <div class="flex flex-col gap-5" x-ref="transcript">
            @if ($context && $context['source'])
                <p class="text-xs text-gray-500">
                    {{ __('Continued from') }}
                    <a href="{{ \Packstub\Agents\Filament\Pages\Chat::getUrl(['conversation' => $context['source']]) }}" class="font-medium text-primary-600 hover:underline">{{ $context['sourceTitle'] ?? __('an earlier chat') }}</a>
                    — {{ __('the assistant starts from a summary of it.') }}
                </p>
            @endif

            @foreach ($this->messages() as $message)
                @if ($message['role'] === 'user')
                    <div class="flex justify-end">
                        <div class="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-sm bg-primary-600 px-4 py-2.5 text-sm text-white shadow-sm">{!! $message['html'] !!}</div>
                    </div>
                    @if ($message['unanswered'])
                        {{-- Recorded before the provider was called, but never answered: offer to send it again. --}}
                        <div class="flex items-center justify-end gap-2 text-xs text-gray-500" wire:loading.remove wire:target="send,decide,retry">
                            <x-filament::icon icon="heroicon-m-exclamation-circle" class="h-4 w-4 text-danger-500" />
                            <span>{{ __('The assistant did not answer.') }}</span>
                            <x-filament::link tag="button" wire:click="retry" size="sm" icon="heroicon-m-arrow-path">{{ __('Retry') }}</x-filament::link>
                        </div>
                    @endif
                @else
                    <div class="flex flex-col gap-2">
                        @if ($message['tools'])
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($message['tools'] as $tool)
                                    @if ($tool['readOnly'])
                                        <x-filament::badge color="gray" size="sm" icon="heroicon-m-magnifying-glass">{{ $tool['name'] }}</x-filament::badge>
                                    @endif
                                @endforeach
                            </div>
                        @endif

                        @foreach ($message['tools'] as $tool)
                            @unless ($tool['readOnly'])
                                <div class="rounded-xl border {{ $tool['pending'] ? 'border-warning-300 bg-warning-50 dark:border-warning-500/40 dark:bg-warning-500/10' : 'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5' }} p-4">
                                    <div class="flex items-center gap-2 text-sm font-semibold text-gray-950 dark:text-white">
                                        <x-filament::icon icon="heroicon-m-bolt" class="h-4 w-4 text-warning-600" />
                                        {{ $tool['name'] }}
                                        @if (! $tool['pending'] && $tool['result'] !== null)
                                            <x-filament::badge :color="$tool['rejected'] ? 'gray' : 'success'" size="sm">
                                                {{ $tool['rejected'] ? __('Rejected') : __('Done') }}
                                            </x-filament::badge>
                                        @endif
                                    </div>
                                    <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                        @foreach ($tool['arguments'] as $key => $value)
                                            <dt class="text-gray-500">{{ \Illuminate\Support\Str::headline($key) }}</dt>
                                            <dd class="text-gray-800 dark:text-gray-200">{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</dd>
                                        @endforeach
                                    </dl>
                                    @if ($tool['pending'])
                                        <div class="mt-3 flex gap-2">
                                            <x-filament::button size="sm" icon="heroicon-m-check" wire:click="decide('{{ $tool['id'] }}', true)">{{ __('Approve') }}</x-filament::button>
                                            <x-filament::button size="sm" color="gray" outlined wire:click="decide('{{ $tool['id'] }}', false)">{{ __('Reject') }}</x-filament::button>
                                        </div>
                                    @endif
                                </div>
                            @endunless
                        @endforeach

                        @if (trim($message['html']) !== '')
                            <div class="fi-chat-md max-w-none text-sm text-gray-800 dark:text-gray-200">{!! $message['html'] !!}</div>
                        @endif

                        @foreach ($message['tables'] as $i => $table)
                            <div class="fi-chat-table-ctn" wire:key="table-{{ $message['id'] }}-{{ $i }}">
                                @livewire('packstub-agents.agent-table', $table, key('agent-table-'.$message['id'].'-'.$i))
                            </div>
                        @endforeach

                        @foreach ($message['charts'] as $i => $chart)
                            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900" wire:key="chart-{{ $message['id'] }}-{{ $i }}">
                                @if ($chart['title'])
                                    <p class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">{{ $chart['title'] }}</p>
                                @endif
                                <div
                                    x-load
                                    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                                    wire:ignore
                                    x-data="chart({
                                        cachedData: @js($chart['data']),
                                        options: @js(['responsive' => true, 'maintainAspectRatio' => false, 'plugins' => ['legend' => ['display' => count($chart['data']['datasets']) > 1 || in_array($chart['type'], ['pie', 'doughnut'])]]]),
                                        type: @js($chart['type']),
                                    })"
                                    class="fi-wi-chart-frame fi-wi-chart-frame-no-aspect-ratio fi-wi-chart-canvas-ctn fi-chat-chart"
                                    style="height: 18rem"
                                >
                                    <canvas x-ref="canvas" role="img" aria-label="{{ $chart['title'] }}" style="width: 100%; height: 100%"></canvas>
                                    <span x-ref="backgroundColorElement" class="fi-wi-chart-bg-color"></span>
                                    <span x-ref="borderColorElement" class="fi-wi-chart-border-color"></span>
                                    <span x-ref="gridColorElement" class="fi-wi-chart-grid-color"></span>
                                    <span x-ref="textColorElement" class="fi-wi-chart-text-color"></span>
                                </div>
                            </div>
                        @endforeach

                        @if (trim($message['html']) !== '')
                            <div class="flex items-center gap-1 text-gray-400">
                                <button type="button" wire:click="feedback('{{ $message['id'] }}', 'up')" class="rounded p-1 hover:text-success-600 {{ $message['rating'] === 'up' ? 'text-success-600' : '' }}" title="{{ __('Helpful') }}">
                                    <x-filament::icon icon="heroicon-m-hand-thumb-up" class="h-4 w-4" />
                                </button>
                                <button type="button" wire:click="feedback('{{ $message['id'] }}', 'down')" class="rounded p-1 hover:text-danger-600 {{ $message['rating'] === 'down' ? 'text-danger-600' : '' }}" title="{{ __('Not helpful') }}">
                                    <x-filament::icon icon="heroicon-m-hand-thumb-down" class="h-4 w-4" />
                                </button>
                                <span class="ml-1 text-xs">{{ $message['at']?->format('H:i') }}</span>
                            </div>
                        @endif
                    </div>
                @endif
            @endforeach

            {{-- Live areas. The question being answered is drawn client-side the moment it is sent (agent-chat.js) and
                 handed over to the persisted transcript on re-render; the answer and the tool status stream in. --}}
            <div wire:ignore class="flex justify-end empty:hidden">
                <template x-if="sending">
                    <div class="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-sm bg-primary-600 px-4 py-2.5 text-sm text-white shadow-sm" x-text="sending.text"></div>
                </template>
            </div>
            <div wire:stream="answer" class="fi-chat-md max-w-none text-sm text-gray-800 empty:hidden dark:text-gray-200" :class="{ 'fi-chat-streaming': busy }"></div>
            <div wire:loading wire:target="send,decide,retry" class="flex items-center gap-2 text-xs text-gray-500">
                <x-filament::loading-indicator class="h-4 w-4" />
                <span wire:stream="status">{{ __('Thinking…') }}</span>
            </div>

            {{-- Questions typed while an answer was still streaming: sent next, one at a time; editable until then. --}}
            <div wire:ignore class="flex flex-col gap-5 empty:hidden">
                <template x-for="queued in queue" :key="queued.id">
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
        </div>

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

        @if ($context && $context['tokens'] > 0)
            {{-- How much of the history window this chat uses: what does not fit is summarized for the model. --}}
            <div class="fi-chat-context flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500" wire:loading.remove wire:target="send,decide,retry">
                <span class="fi-chat-context-bar" title="{{ __(':used of :budget tokens of history', ['used' => number_format($context['tokens']), 'budget' => number_format($context['budget'])]) }}">
                    <span class="fi-chat-context-fill {{ $context['notice'] ? 'fi-chat-context-fill-high' : '' }}" style="width: {{ (int) round($context['share'] * 100) }}%"></span>
                </span>
                <span>{{ __('Context :percent%', ['percent' => (int) round($context['share'] * 100)]) }}</span>
                @if ($context['summarized'] && ! $context['source'])
                    <span>· {{ __('older messages are summarized for the assistant') }}</span>
                @endif
                @if ($context['notice'])
                    <span class="text-gray-700 dark:text-gray-300">· {{ __('This chat is getting long — answers stay sharpest in a new one.') }}</span>
                    <x-filament::link tag="button" wire:click="continueInNewChat" size="sm" icon="heroicon-m-arrow-right-circle">{{ __('Continue in a new chat') }}</x-filament::link>
                @endif
            </div>
        @endif

        <x-packstub-agents::composer method="send" targets="send,decide,retry" class="sticky bottom-4 shadow-lg" />
    </div>
</x-filament-panels::page>
