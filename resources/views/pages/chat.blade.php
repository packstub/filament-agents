<x-filament-panels::page>
    <div class="fi-chat mx-auto flex w-full max-w-6xl flex-col gap-6" x-data x-init="@if ($autoSend) $wire.send() @endif">
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

        <div class="flex flex-col gap-5">
            @foreach ($this->messages() as $message)
                @if ($message['role'] === 'user')
                    <div class="flex justify-end">
                        <div class="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-sm bg-primary-600 px-4 py-2.5 text-sm text-white shadow-sm">{!! $message['html'] !!}</div>
                    </div>
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

            {{-- Live areas: the question just sent, the streaming answer and the tool status. Cleared by the re-render. --}}
            <div class="flex justify-end empty:hidden">
                <div wire:stream="pending-user" class="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-sm bg-primary-600 px-4 py-2.5 text-sm text-white shadow-sm empty:hidden"></div>
            </div>
            <div wire:stream="answer" class="fi-chat-md max-w-none text-sm text-gray-800 empty:hidden dark:text-gray-200"></div>
            <div wire:loading wire:target="send,decide" class="flex items-center gap-2 text-xs text-gray-500">
                <x-filament::loading-indicator class="h-4 w-4" />
                <span wire:stream="status">{{ __('Thinking…') }}</span>
            </div>
        </div>

        <x-packstub-agents::composer method="send" targets="send,decide" class="sticky bottom-4 shadow-lg" />
    </div>
</x-filament-panels::page>
