<?php

namespace Packstub\Agents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Packstub\Agents\Enums\ApprovalStatus;

class PendingApproval extends Model
{
    protected $table = 'agent_pending_approvals';

    protected $fillable = [
        'agent_token_id',
        'tool',
        'arguments',
        'proposed_changes',
        'status',
        'decided_by',
        'decided_at',
        'applied_at',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'proposed_changes' => 'array',
            'result' => 'array',
            'status' => ApprovalStatus::class,
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function agentToken(): BelongsTo
    {
        return $this->belongsTo(AgentToken::class);
    }

    public function decidedBy(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');

        return $this->belongsTo($userModel, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }
}
