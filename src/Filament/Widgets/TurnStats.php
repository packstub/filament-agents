<?php

namespace Packstub\Agents\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Packstub\Agents\Models\AgentMessageFeedback;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentPricing;

/**
 * The numbers over the AI turns table: turns today and this week, tokens
 * and cost this week, the share of turns that failed, and how the answers
 * were rated — with a spark line of the last seven days under each.
 */
class TurnStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $since = now()->subDays(6)->startOfDay();
        $week = AgentTurn::query()->where('created_at', '>=', $since)->whereNotIn('status', AgentTurn::OPEN)->get(['id', 'status', 'usage', 'cost', 'duration_ms', 'created_at']);
        $today = $week->filter(fn (AgentTurn $t) => $t->created_at?->isToday());
        $days = collect(range(6, 0))->map(fn (int $back) => now()->subDays($back)->toDateString());
        $perDay = fn (callable $value) => $days->map(fn (string $day) => (float) $week->filter(fn (AgentTurn $t) => $t->created_at?->toDateString() === $day)->sum($value))->all();

        $tokens = $week->sum(fn (AgentTurn $t) => ($t->tokensIn() ?? 0) + ($t->tokensOut() ?? 0));
        $priced = $week->filter(fn (AgentTurn $t) => $t->cost !== null);
        $failed = $week->whereIn('status', [AgentTurn::FAILED])->count();
        $ratings = AgentMessageFeedback::query()->where('created_at', '>=', $since)->get(['rating']);
        $up = $ratings->where('rating', 'up')->count();

        return [
            Stat::make(__('Turns today'), number_format($today->count()))
                ->description(__(':count this week', ['count' => number_format($week->count())]))
                ->chart($perDay(fn () => 1)),
            Stat::make(__('Tokens this week'), self::compact($tokens))
                ->description($priced->isNotEmpty() ? __('Cost :cost', ['cost' => AgentPricing::format((float) $priced->sum('cost'))]) : __('No prices set'))
                ->chart($perDay(fn (AgentTurn $t) => ($t->tokensIn() ?? 0) + ($t->tokensOut() ?? 0))),
            Stat::make(__('Failed'), $week->count() > 0 ? round($failed / $week->count() * 100).'%' : '—')
                ->description(trans_choice('{0} no failed turn this week|{1} :count failed turn this week|[2,*] :count failed turns this week', $failed))
                ->color($failed > 0 && $week->count() > 0 && $failed / $week->count() > 0.1 ? 'danger' : 'gray')
                ->chart($perDay(fn (AgentTurn $t) => $t->status === AgentTurn::FAILED ? 1 : 0)),
            Stat::make(__('Rated helpful'), $ratings->count() > 0 ? round($up / $ratings->count() * 100).'%' : '—')
                ->description(trans_choice('{0} no rating this week|{1} :count rating this week|[2,*] :count ratings this week', $ratings->count()))
                ->color($ratings->count() > 0 && $up / $ratings->count() < 0.5 ? 'warning' : 'gray'),
        ];
    }

    protected static function compact(int|float $n): string
    {
        return match (true) {
            $n >= 1_000_000 => number_format($n / 1_000_000, 1).'M',
            $n >= 1_000 => number_format($n / 1_000, 1).'k',
            default => number_format($n),
        };
    }
}
