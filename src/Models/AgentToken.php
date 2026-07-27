<?php

namespace Packstub\Agents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Packstub\Agents\Enums\ToolMode;

class AgentToken extends Model
{
    protected $fillable = [
        'name',
        'public_id',
        'secret_hash',
        'grants',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'secret_hash',
    ];

    protected function casts(): array
    {
        return [
            'grants' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function auditEntries(): HasMany
    {
        return $this->hasMany(AgentAuditEntry::class);
    }

    public function pendingApprovals(): HasMany
    {
        return $this->hasMany(PendingApproval::class);
    }

    public function grantFor(string $toolName): ?ToolMode
    {
        $grant = ($this->grants ?? [])[$toolName] ?? null;

        return is_string($grant) ? ToolMode::tryFrom($grant) : null;
    }

    public function allows(string $toolName, ToolMode $required): bool
    {
        return $this->grantFor($toolName)?->covers($required) ?? false;
    }

    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
