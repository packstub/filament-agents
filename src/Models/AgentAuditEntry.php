<?php

namespace Packstub\Agents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Packstub\Agents\Enums\AuditDecision;
use Packstub\Agents\Enums\ToolMode;

class AgentAuditEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'agent_token_id',
        'tool',
        'mode',
        'decision',
        'arguments',
        'summary',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'mode' => ToolMode::class,
            'decision' => AuditDecision::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * The audit trail is append-only: silently ignoring updates or deletes at
     * the model layer keeps every code path (including future Filament
     * actions) from rewriting history.
     */
    protected static function booted(): void
    {
        static::updating(fn (): bool => false);
        static::deleting(fn (): bool => false);
    }

    public function agentToken(): BelongsTo
    {
        return $this->belongsTo(AgentToken::class);
    }
}
