<?php

namespace Packstub\Agents\Support;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Packstub\Agents\Models\AgentMessageFeedback;
use Packstub\Agents\Models\ConversationSummary;
use Throwable;

/**
 * laravel/ai records a question and its answer together, once the answer has
 * arrived — so a question the provider never answered was lost. This store
 * lets the chat record the question first: storeQuestion() writes the row
 * before the provider is called, answering() tells the store which row the
 * turn belongs to, and the SDK then attaches the answer to it (the question
 * is neither stored twice nor fed back to the model as history).
 *
 * It also decides what a long chat replays (config `history`): the most
 * recent turns that fit a token budget, older tool results reduced to a
 * placeholder, and — when a summarizer is set for the turn — the messages
 * that fall out of the window folded into a rolling summary the model reads
 * first. Without a summarizer they are simply left out, as laravel/ai does.
 */
class AgentConversationStore extends DatabaseConversationStore
{
    /** The pre-stored question the running turn answers, if any. */
    protected ?string $answering = null;

    /** fn (string $prompt): string — writes the rolling summary with a cheap model; null: no compaction this turn. */
    protected ?Closure $summarizer = null;

    /** At most this many rows are read per load (bounds the first compaction of a very long chat). */
    public const SUMMARY_ROWS_CAP = 400;

    /** Open a conversation for the person, titled after the first question until the answer arrives. */
    public function startConversation(object $participant, string $prompt): string
    {
        return $this->storeConversation(
            Conversation::participantType($participant),
            Conversation::participantKey($participant),
            Str::limit($prompt, 50, preserveWords: true),
        );
    }

    /** Record a question before it is sent to the provider and return the message ID. */
    public function storeQuestion(string $conversationId, object $participant, string $agentClass, string $content): string
    {
        $messageId = (string) Str::uuid7();
        $now = now();

        $this->table($this->messagesTable())->insert($this->messageAttributes(
            $messageId,
            $conversationId,
            Conversation::participantType($participant),
            Conversation::participantKey($participant),
            $now,
            [
                'agent' => $agentClass,
                'role' => 'user',
                'content' => $content,
                'attachments' => '[]',
                'tool_calls' => '[]',
                'tool_results' => '[]',
                'usage' => '[]',
                'meta' => '[]',
                'approval_state' => null,
            ],
        ));

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /**
     * Store what the assistant had written when the person stopped it. laravel/ai stores an answer only once the
     * stream has ended, so a stopped turn stores its own, marked in meta so the page can say so.
     */
    public function storeStoppedAnswer(string $conversationId, object $participant, string $agentClass, string $content): string
    {
        $messageId = (string) Str::uuid7();
        $now = now();

        $this->table($this->messagesTable())->insert($this->messageAttributes(
            $messageId,
            $conversationId,
            Conversation::participantType($participant),
            Conversation::participantKey($participant),
            $now,
            [
                'agent' => $agentClass,
                'role' => 'assistant',
                'content' => $content,
                'attachments' => '[]',
                'tool_calls' => '[]',
                'tool_results' => '[]',
                'usage' => '[]',
                'meta' => json_encode(['stopped' => true]),
                'approval_state' => null,
            ],
        ));

        $this->touchConversation($conversationId, $now);

        return $messageId;
    }

    /** Whether a stored answer was cut short by the person. */
    public static function wasStopped(mixed $meta): bool
    {
        $meta = is_string($meta) ? json_decode($meta, true) : $meta;

        return (bool) (is_array($meta) ? ($meta['stopped'] ?? false) : false);
    }

    /**
     * Mark the newest answer of the conversation as ended early by the provider (AgentTurns::cutShortReason),
     * on the row laravel/ai stored for it.
     */
    public function markCutShort(string $conversationId, string $reason): void
    {
        $row = $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->first(['id', 'meta']);

        if (! $row) {
            return;
        }

        $meta = is_string($row->meta) ? json_decode($row->meta, true) : $row->meta;

        $this->table($this->messagesTable())
            ->where('id', $row->id)
            ->update(['meta' => json_encode([...(is_array($meta) ? $meta : []), 'cut_short' => $reason])]);
    }

    /** Why a stored answer was ended early by the provider, or null. */
    public static function cutShort(mixed $meta): ?string
    {
        $meta = is_string($meta) ? json_decode($meta, true) : $meta;
        $reason = is_array($meta) ? ($meta['cut_short'] ?? null) : null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * Forget everything after a question (its answer, a paused proposal, the feedback on them) so the question
     * can be answered again — Regenerate, and Edit on the last question. The rolling summary is not touched:
     * it only ever covers rows older than the last exchange.
     */
    public function dropMessagesAfter(string $conversationId, string $messageId): void
    {
        $ids = $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->where('id', '>', $messageId)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        AgentMessageFeedback::query()->whereIn('message_id', $ids)->delete();
        $this->table($this->messagesTable())->whereIn('id', $ids)->delete();
        $this->touchConversation($conversationId, now());
    }

    /** Replace the text of a recorded question (Edit on the last question). */
    public function rewriteQuestion(string $conversationId, string $messageId, string $content): void
    {
        $this->table($this->messagesTable())->where('conversation_id', $conversationId)->where('id', $messageId)->where('role', 'user')->update(['content' => $content, 'updated_at' => now()]);
        $this->touchConversation($conversationId, now());
    }

    /** The turn about to run answers this pre-stored question (null: none, the SDK stores the question itself). */
    public function answering(?string $messageId): void
    {
        $this->answering = $messageId;
    }

    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt): string
    {
        if ($this->answering === null) {
            return parent::storeUserMessage($conversationId, $participantType, $participantId, $prompt);
        }

        $messageId = $this->answering;
        $this->answering = null;
        $this->touchConversation($conversationId, now());

        return $messageId;
    }

    /** The summarizer the next history load may use for compaction (null: leave dropped messages out silently). */
    public function summarizeWith(?Closure $summarizer): void
    {
        $this->summarizer = $summarizer;
    }

    /** A summarizer on the provider's cheapest model, as titleConversation() uses it. */
    public static function providerSummarizer(TextProvider $provider): Closure
    {
        return fn (string $prompt): string => (string) $provider->textGenerationLoop()->generate(
            $provider,
            $provider->cheapestTextModel(),
            'You maintain the running summary of a conversation between a person and a back-office assistant. Merge the existing summary (if any) with the new messages into one summary of at most 300 words, in the language of the conversation. Keep every fact, number, record identifier, decision, open question and what the person asked for; drop pleasantries and the assistant\'s wording. Respond with the summary only.',
            [new UserMessage($prompt)],
        )->text;
    }

    /**
     * The history the model reads: the summary so far (if any), then the most recent
     * turns that fit the budget with older tool results pruned. What no longer fits is
     * summarized when a summarizer is set. While a pre-stored question is being answered
     * it is the conversation's last row and the SDK sends it as the prompt — so it is left
     * out here rather than shown twice.
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        $summary = ConversationSummary::query()->where('conversation_id', $conversationId)->first();
        $records = $this->recordsAfter($conversationId, $summary?->through_message_id); // newest first
        [$kept, $dropped] = $this->fitBudget($records, $limit, $summary);

        if ($dropped->isNotEmpty() && $this->summarizer !== null) {
            $summary = $this->compact($conversationId, $summary, $dropped->reverse()->values()) ?? $summary;
        }

        $messages = $kept->isEmpty() ? collect() : parent::getLatestConversationMessages($conversationId, $kept->count());
        $messages = $this->pruneToolResults($messages);

        if ($this->answering !== null && $this->isUserMessage($messages->last())) {
            $messages->pop();
        }

        if ($summary !== null) {
            $messages->prepend(new AssistantMessage(__('Understood, I will build on that summary.')));
            $messages->prepend(new Message('user', __('Summary of the earlier part of this conversation (those messages are not shown again):')."\n\n".$summary->content));
        }

        return $messages;
    }

    /**
     * How full the history window is, for the chat page's meter.
     *
     * @return array{tokens: int, budget: int, share: float, summarized: bool, source: ?string}
     */
    public function contextUsage(string $conversationId): array
    {
        $summary = ConversationSummary::query()->where('conversation_id', $conversationId)->first();
        $records = $this->recordsAfter($conversationId, $summary?->through_message_id);
        $budget = self::budget();
        $tokens = ($summary ? self::estimateTokens($summary->content) : 0)
            + $records->values()->sum(fn ($record, int $i) => $this->estimateRecord($record, $i));

        return [
            'tokens' => $tokens,
            'budget' => $budget,
            'share' => $budget > 0 ? min(1.0, $tokens / $budget) : 0.0,
            'summarized' => $summary !== null,
            'source' => $summary?->source_conversation_id,
        ];
    }

    /**
     * Open a new conversation that carries a summary of this one, and return its ID.
     * The summary covers everything the model would read now: the summary so far plus the rows after it.
     */
    public function continueConversation(string $conversationId, object $participant, string $title, Closure $summarizer): string
    {
        $summary = ConversationSummary::query()->where('conversation_id', $conversationId)->first();
        $records = $this->recordsAfter($conversationId, $summary?->through_message_id)->reverse()->values();
        $content = $summarizer($this->summaryPrompt($summary?->content, $records));

        $newId = $this->storeConversation(Conversation::participantType($participant), Conversation::participantKey($participant), $title);

        ConversationSummary::query()->create([
            'conversation_id' => $newId,
            'through_message_id' => null,
            'source_conversation_id' => $conversationId,
            'content' => $content,
        ]);

        return $newId;
    }

    /** The conversation's rows newer than $afterId (all of them when null), newest first, capped. */
    protected function recordsAfter(string $conversationId, ?string $afterId): Collection
    {
        return $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->when($afterId !== null, fn ($query) => $query->where('id', '>', $afterId))
            ->orderByDesc('id')
            ->limit(self::SUMMARY_ROWS_CAP)
            ->get();
    }

    /**
     * Split newest-first rows into what fits the budget (at most $limit rows, cut on a turn boundary so the
     * oldest kept row is a question) and what drops out.
     *
     * @return array{0: Collection, 1: Collection}
     */
    protected function fitBudget(Collection $records, int $limit, ?ConversationSummary $summary): array
    {
        $budget = self::budget() - ($summary ? self::estimateTokens($summary->content) : 0);
        $used = 0;
        $kept = collect();

        foreach ($records->values() as $i => $record) {
            $used += $this->estimateRecord($record, $i);

            if ($kept->isNotEmpty() && ($used > $budget || $kept->count() >= $limit)) {
                break;
            }

            $kept->push($record);
        }

        // Cut on a turn boundary: the oldest kept row must be a question, or a tool call could lose its result.
        while ($kept->count() > 1 && $kept->last()->role !== 'user') {
            $kept->pop();
        }

        return [$kept, $records->values()->slice($kept->count())->values()];
    }

    /** Fold $dropped (oldest first) into the rolling summary; null when the summarizer failed (the rows are left out this turn). */
    protected function compact(string $conversationId, ?ConversationSummary $summary, Collection $dropped): ?ConversationSummary
    {
        try {
            $content = ($this->summarizer)($this->summaryPrompt($summary?->content, $dropped));
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (trim($content) === '') {
            return null;
        }

        return ConversationSummary::query()->updateOrCreate(
            ['conversation_id' => $conversationId],
            ['content' => trim($content), 'through_message_id' => $dropped->last()->id],
        );
    }

    /** What the summarizer reads: the summary so far and the rows (oldest first) to fold into it, tool payloads reduced to a line. */
    protected function summaryPrompt(?string $existing, Collection $records): string
    {
        $lines = $records->map(function ($record): string {
            $content = Str::limit(trim((string) $record->content), 1200);
            $calls = collect(json_decode((string) $record->tool_calls, true) ?: [])
                ->map(fn ($call) => ($call['name'] ?? 'tool').' '.json_encode($call['arguments'] ?? [], JSON_UNESCAPED_UNICODE))
                ->implode('; ');

            return ($record->role === 'user' ? 'Person: ' : 'Assistant: ').$content.($calls !== '' ? " [called: {$calls}]" : '');
        })->implode("\n");

        return ($existing !== null ? "Existing summary:\n{$existing}\n\n" : '')."New messages:\n{$lines}";
    }

    /** Tool results before the last keep_tool_results_turns questions are replaced by a placeholder; the calls stay. */
    protected function pruneToolResults(Collection $messages): Collection
    {
        $keep = self::keepToolResultsTurns();
        $turns = 0;

        for ($i = $messages->count() - 1; $i >= 0; $i--) {
            $message = $messages[$i];

            if ($this->isUserMessage($message)) {
                $turns++;

                continue;
            }

            if ($turns >= $keep && $message instanceof ToolResultMessage) {
                $message->toolResults = $message->toolResults->map(fn (ToolResult $result) => new ToolResult(
                    $result->id,
                    $result->name,
                    $result->arguments,
                    self::placeholder($result->name, strlen(is_string($result->result) ? $result->result : json_encode($result->result))),
                    $result->resultId,
                    $result->denied,
                ));
            }
        }

        return $messages;
    }

    protected static function placeholder(string $tool, int $bytes): string
    {
        return "[{$tool} result omitted from history ({$bytes} bytes); call the tool again if you need it]";
    }

    /** A row's share of the window, as the model would read it ($index counts rows from the newest). */
    protected function estimateRecord(object $record, int $index): int
    {
        $pruned = $index >= self::keepToolResultsTurns() * 2;
        $results = (string) $record->tool_results;

        return self::estimateTokens((string) $record->content)
            + self::estimateTokens((string) $record->tool_calls)
            + ($pruned && strlen($results) > 2 ? 40 : self::estimateTokens($results));
    }

    /** A rough token count (four characters per token) — enough to decide what fits. */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    public static function budget(): int
    {
        return max(1000, (int) config('packstub-agents.history.max_tokens', 24000));
    }

    public static function keepToolResultsTurns(): int
    {
        return max(0, (int) config('packstub-agents.history.keep_tool_results_turns', 3));
    }

    /** Title a conversation the way laravel/ai does once its first answer is in: a short provider-written line, else the question. */
    public function titleConversation(string $conversationId, string $prompt, TextProvider $provider): void
    {
        if (! (bool) config('ai.conversations.generate_title', true)) {
            return;
        }

        try {
            $response = $provider->textGenerationLoop()->generate(
                $provider,
                $provider->cheapestTextModel(),
                'Generate a concise 3-5 word title for a conversation that starts with the following message. Use the same language as the message. Respond with only the title, no quotes or punctuation.',
                [new UserMessage(Str::limit($prompt, 500))],
            );

            if (($title = trim(Str::limit($response->text, 100))) !== '') {
                $this->table($this->conversationsTable())->where('id', $conversationId)->update(['title' => $title]);
            }
        } catch (Throwable) {
            // The question stays as the title.
        }
    }

    protected function isUserMessage(mixed $message): bool
    {
        return $message instanceof Message && $message->role === MessageRole::User;
    }
}
