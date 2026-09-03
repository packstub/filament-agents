<?php

namespace Packstub\Agents\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Models\AgentLimit;

/**
 * The limits that apply to one person in one workspace: config defaults,
 * overridden by the operator's global row, then the workspace's row, then
 * the user's row (user-level fields only). Edited in the operator panel
 * (AI limits), consulted by AgentBudget before every turn.
 */
class AgentLimits
{
    /** @var array<string, array<string, mixed>> */
    protected static array $cache = [];

    /** @return array{enabled: bool, turns_per_minute: ?int, turns_per_day: ?int, tokens_per_month: ?int, user_tokens_per_day: ?int, user_tokens_per_month: ?int, prompt_max_chars: ?int} */
    public static function effective(?Model $tenant = null, ?Authenticatable $user = null): array
    {
        $tenant ??= Agents::tenant();
        $user ??= auth()->user();
        $key = ($tenant?->getKey() ?? '-').'|'.($user?->getAuthIdentifier() ?? '-');

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $limits = collect(config('packstub-agents.limits', []))->only(AgentLimit::FIELDS)->map(fn ($v) => $v ?: null)->all()
            + ['enabled' => true, 'turns_per_minute' => null, 'turns_per_day' => null, 'tokens_per_month' => null,
                'user_tokens_per_day' => null, 'user_tokens_per_month' => null, 'prompt_max_chars' => null];

        $rows = AgentLimit::query()
            ->where(fn ($q) => $q->where('scope', 'global')
                ->when($tenant, fn ($q) => $q->orWhere(fn ($w) => $w->where('scope', 'tenant')->where('scope_id', (string) $tenant->getKey())))
                ->when($user, fn ($q) => $q->orWhere(fn ($w) => $w->where('scope', 'user')->where('scope_id', (string) $user->getAuthIdentifier()))))
            ->get()
            ->keyBy('scope');

        foreach (['global', 'tenant', 'user'] as $scope) {
            if (! $row = $rows->get($scope)) {
                continue;
            }
            foreach ($scope === 'user' ? AgentLimit::USER_FIELDS : AgentLimit::FIELDS as $field) {
                if ($row->{$field} !== null) {
                    $limits[$field] = $field === 'enabled' ? (bool) $row->{$field} : (int) $row->{$field};
                }
            }
        }

        return self::$cache[$key] = $limits;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
