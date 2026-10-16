{{-- Under an answer: the record tables and charts its tools returned. Reads $message. Part of pages/chat.blade.php,
     included with its variables; publish this file alone to change only this part. --}}
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
