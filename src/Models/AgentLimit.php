<?php

namespace Packstub\Agents\Models;

use Illuminate\Database\Eloquent\Model;
use Packstub\Agents\Facades\Agents;

/**
 * One row of AI limits: scope "global" (no scope_id), "tenant" (scope_id =
 * tenant key) or "user" (scope_id = user id). Null values inherit. Lives on
 * packstub-agents.limits_connection (the central connection in a
 * database-per-tenant app).
 */
class AgentLimit extends Model
{
    public const array SCOPES = ['global', 'tenant', 'user'];

    public const array FIELDS = ['enabled', 'turns_per_minute', 'turns_per_day', 'tokens_per_month', 'user_tokens_per_day', 'user_tokens_per_month', 'prompt_max_chars'];

    /** Fields a per-user row may override (the rest are workspace-wide by nature). */
    public const array USER_FIELDS = ['enabled', 'turns_per_minute', 'user_tokens_per_day', 'user_tokens_per_month', 'prompt_max_chars'];

    protected $guarded = [];

    protected $casts = ['enabled' => 'bool'];

    public function getConnectionName(): ?string
    {
        return config('packstub-agents.limits_connection') ?: parent::getConnectionName();
    }

    public function targetLabel(): string
    {
        return match ($this->scope) {
            'tenant' => self::tenantName($this->scope_id) ?? __('Workspace :id', ['id' => $this->scope_id]),
            'user' => self::userLabel($this->scope_id) ?? __('User :id', ['id' => $this->scope_id]),
            default => __('Everyone'),
        };
    }

    /** @return class-string<Model>|null */
    public static function tenantModel(): ?string
    {
        return Agents::panel()?->getTenantModel();
    }

    /** @return class-string<Model> */
    public static function userModel(): string
    {
        return config('auth.providers.users.model');
    }

    public static function tenantName(mixed $key): ?string
    {
        $model = self::tenantModel();
        $tenant = $model ? $model::query()->find($key) : null;

        return $tenant ? (method_exists($tenant, 'getFilamentName') ? $tenant->getFilamentName() : ($tenant->name ?? null)) : null;
    }

    public static function userLabel(mixed $key): ?string
    {
        $user = self::userModel()::query()->find($key);

        return $user ? ($user->email ?? $user->name ?? null) : null;
    }
}
