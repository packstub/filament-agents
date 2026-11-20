{{-- A chart under an answer, drawn by Filament's chart widget component (the panel's colours and theme). Reads $chart. --}}
<div
    x-load
    x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
    data-agents-ignore
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
