<?php

namespace Packstub\Agents\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One turn of the chat — a question, a retry or a set of approval decisions —
 * from the moment it is sent until the answer is stored: queued behind
 * another turn, pending on the queue, running (the answer streams into
 * `text`), then done, stopped or failed. The chat page and the poll endpoint
 * read it; the RunAgentTurn job writes it. When the turn ends the row is
 * also its record: provider and model, token usage, tools called, wall
 * time and how it ended — the operator's AI turns page and the log line
 * read those. Ended rows are pruned after chat.keep_turns_days.
 */
class AgentTurn extends Model
{
    use MassPrunable;

    public const string QUEUED = 'queued';

    public const string PENDING = 'pending';

    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string STOPPED = 'stopped';

    public const string FAILED = 'failed';

    /** The statuses of a turn that has not ended yet. */
    public const array OPEN = [self::QUEUED, self::PENDING, self::RUNNING];

    /** The statuses of a turn that has been handed to the queue (or is running). */
    public const array ACTIVE = [self::PENDING, self::RUNNING];

    protected $table = 'agent_turns';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'input' => 'array',
        'usage' => 'array',
        'tool_calls' => 'array',
        'stop_requested_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        return config('ai.conversations.connection');
    }

    /** The question this turn sends, or null for a set of approval decisions. */
    public function prompt(): ?string
    {
        return $this->input['prompt'] ?? null;
    }

    /** @return array<string, bool>|null call id => approved */
    public function decisions(): ?array
    {
        return $this->input['decisions'] ?? null;
    }

    /** Tokens the provider read: the prompt, cached or not. Null until the turn ended with a usage. */
    public function tokensIn(): ?int
    {
        return $this->usage === null ? null : (int) ($this->usage['prompt_tokens'] ?? 0) + (int) ($this->usage['cache_read_input_tokens'] ?? 0) + (int) ($this->usage['cache_write_input_tokens'] ?? 0);
    }

    /** Tokens the provider wrote: the answer and the reasoning. */
    public function tokensOut(): ?int
    {
        return $this->usage === null ? null : (int) ($this->usage['completion_tokens'] ?? 0) + (int) ($this->usage['reasoning_tokens'] ?? 0);
    }

    /** Ended turns older than chat.keep_turns_days go; null keeps them all. Run `model:prune --model=…` daily. */
    public function prunable(): Builder
    {
        $days = config('packstub-agents.chat.keep_turns_days');

        if (! $days) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->whereNotIn('status', self::OPEN)->where('created_at', '<', now()->subDays((int) $days));
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function isEnded(): bool
    {
        return ! $this->isOpen();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE);
    }

    public function scopeForConversation(Builder $query, string $conversationId): Builder
    {
        return $query->where('conversation_id', $conversationId);
    }
}
