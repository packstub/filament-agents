<x-filament-panels::page>
    <div class="fi-chat mx-auto flex w-full max-w-3xl flex-col gap-6" x-data x-init="@if ($autoSend) $wire.send() @endif">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="fi-chat-crumbs truncate text-xs text-gray-500">
                    {{ \Packstub\Agents\Facades\Agents::name() }}
                    @if ($label = $this->contextLabel())
                        <span class="mx-1 text-gray-400">/</span>{{ __('About :record', ['record' => $label]) }}
                    @endif
                </p>
                <h1 class="mt-0.5 truncate text-xl font-semibold text-gray-950 dark:text-white">{{ $this->getTitle() }}</h1>
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
                        <div class="fi-chat-question max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-sm bg-primary-600 px-4 py-2.5 text-sm text-white shadow-sm">{!! $message['html'] !!}</div>
                    </div>
                @else
                    <div class="fi-chat-answer flex flex-col gap-2.5">
                        {{-- What the assistant looked at: one chip per read tool, named as the tool is registered. --}}
                        @if (collect($message['tools'])->contains('readOnly', true))
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($message['tools'] as $tool)
                                    @if ($tool['readOnly'])
                                        <span class="fi-chat-tool"><span class="fi-chat-tool-dot"></span>{{ $tool['name'] }}</span>
                                    @endif
                                @endforeach
                            </div>
                        @endif

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

                        {{-- A change the assistant wants to make: a proposal with Approve / Reject, then the outcome (Done / Rejected). --}}
                        @foreach ($message['tools'] as $tool)
                            @unless ($tool['readOnly'])
                                @php
                                    $state = $tool['pending'] ? 'pending' : ($tool['rejected'] ? 'rejected' : 'done');
                                    $scalars = array_filter($tool['arguments'], 'is_scalar');
                                    $subject = count($tool['arguments']) === 1 && count($scalars) === 1 ? ' '.reset($scalars) : '';
                                    $call = preg_replace('/\s*\n\s*/', ' ', json_encode($tool['arguments'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                                @endphp
                                <div class="fi-chat-proposal fi-chat-proposal-{{ $state }}">
                                    <div class="fi-chat-proposal-icon">
                                        <x-filament::icon :icon="match ($state) { 'pending' => 'heroicon-m-exclamation-triangle', 'done' => 'heroicon-m-check', default => 'heroicon-m-x-mark' }" class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-gray-950 dark:text-white">
                                            <span>{{ $tool['label'] }}{{ $subject }}{{ $tool['pending'] ? '?' : '' }}</span>
                                            @if (! $tool['pending'] && $tool['result'] !== null)
                                                <x-filament::badge :color="$tool['rejected'] ? 'gray' : 'success'" size="sm">
                                                    {{ $tool['rejected'] ? __('Rejected') : __('Done') }}
                                                </x-filament::badge>
                                            @endif
                                        </p>
                                        <code class="fi-chat-proposal-call">{{ $tool['name'] }} {{ $call }}</code>
                                    </div>
                                    @if ($tool['pending'])
                                        <div class="flex shrink-0 items-center gap-2">
                                            <x-filament::button size="sm" wire:click="decide('{{ $tool['id'] }}', true)">{{ __('Approve') }}</x-filament::button>
                                            <x-filament::button size="sm" color="gray" outlined wire:click="decide('{{ $tool['id'] }}', false)">{{ __('Reject') }}</x-filament::button>
                                        </div>
                                    @endif
                                </div>
                            @endunless
                        @endforeach

                        @if (trim($message['html']) !== '')
                            <div class="fi-chat-feedback {{ $message['rating'] ? 'fi-chat-feedback-rated' : '' }} flex items-center gap-1 text-gray-400">
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
            <div class="fi-chat-stream flex justify-end">
                <div wire:stream="pending-user" class="fi-chat-question max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-sm bg-primary-600 px-4 py-2.5 text-sm text-white shadow-sm empty:hidden"></div>
            </div>
            <div wire:stream="answer" class="fi-chat-md max-w-none text-sm text-gray-800 empty:hidden dark:text-gray-200"></div>
            <div wire:loading wire:target="send,decide" class="flex items-center gap-2 text-xs text-gray-500">
                <x-filament::loading-indicator class="h-4 w-4" />
                <span wire:stream="status">{{ __('Thinking…') }}</span>
            </div>
        </div>

        <x-packstub-agents::composer method="send" targets="send,decide" class="sticky bottom-4" />
    </div>
</x-filament-panels::page>
