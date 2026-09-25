<?php

namespace Packstub\Agents\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Packstub\Agents\Models\AgentTurn;

/** Turns per day over the last two weeks, done against failed, over the AI turns table. */
class TurnsChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '14rem';

    public function getHeading(): ?string
    {
        return __('Turns per day');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $since = now()->subDays(13)->startOfDay();
        $turns = AgentTurn::query()->where('created_at', '>=', $since)->whereNotIn('status', AgentTurn::OPEN)->get(['status', 'created_at']);
        $days = collect(range(13, 0))->map(fn (int $back) => now()->subDays($back));
        $count = fn (string $day, ?string $status) => $turns->filter(fn (AgentTurn $t) => $t->created_at?->toDateString() === $day && ($status === null ? $t->status !== AgentTurn::FAILED : $t->status === $status))->count();

        return [
            'labels' => $days->map(fn ($day) => $day->translatedFormat('D j'))->all(),
            'datasets' => [
                ['label' => __('Answered'), 'data' => $days->map(fn ($day) => $count($day->toDateString(), null))->all(), 'backgroundColor' => 'rgba(99, 102, 241, 0.7)', 'borderRadius' => 4],
                ['label' => __('Failed'), 'data' => $days->map(fn ($day) => $count($day->toDateString(), AgentTurn::FAILED))->all(), 'backgroundColor' => 'rgba(239, 68, 68, 0.7)', 'borderRadius' => 4],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['x' => ['stacked' => true], 'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]]], 'plugins' => ['legend' => ['display' => true]]];
    }
}
