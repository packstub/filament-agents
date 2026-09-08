<?php

namespace Packstub\Agents\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Filament\Pages\Chat;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentModels;
use Packstub\Agents\Support\AgentRuntime;
use Packstub\Agents\Support\AgentTurns;
use RuntimeException;
use Throwable;

/**
 * One turn of the chat, off the request: the agent streams the answer while
 * the job writes what it has so far to the turn row, which the page polls.
 * The person can stop it (the flag is checked between events; the partial
 * answer is stored with a marker), a provider failure keeps the question
 * with a Retry, and when the turn ends the next queued turn of the same
 * conversation is started. On a sync queue the whole thing runs inside the
 * request, as it did before — nothing else changes.
 */
class RunAgentTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** A turn is billed once: it is never retried by the queue. */
    public int $tries = 1;

    public int $timeout;

    /**
     * @param  array{panel: ?string, tenant: ?string, user: int|string|null, locale: ?string}  $runtime
     */
    public function __construct(public string $turnId, public array $runtime)
    {
        $this->timeout = AgentTurns::jobTimeout();
    }

    public function handle(AgentTurns $turns): void
    {
        $leave = AgentRuntime::enter($this->runtime);

        try {
            $turn = AgentTurn::query()->find($this->turnId);

            if (! $turn || ! $turns->claim($turn)) {
                return;
            }

            $this->run($turn, $turns);
        } finally {
            if (isset($turn)) {
                $turns->startNext($turn->conversation_id);
            }

            $leave();
        }
    }

    /** The worker gave up on the job (timeout, lost process): the question keeps its Retry. */
    public function failed(?Throwable $exception): void
    {
        $leave = AgentRuntime::enter($this->runtime);

        try {
            $turn = AgentTurn::query()->find($this->turnId);

            if ($turn && $turn->isOpen()) {
                app(AgentTurns::class)->finish($turn, AgentTurn::FAILED, $exception?->getMessage() ?: __('The answer was interrupted.'));
                app(AgentTurns::class)->startNext($turn->conversation_id);
            }
        } finally {
            $leave();
        }
    }

    protected function run(AgentTurn $turn, AgentTurns $turns): void
    {
        $store = app(AgentConversationStore::class);
        $user = $turns->participant($turn);

        if (! $user) {
            $turns->finish($turn, AgentTurn::FAILED, __('The person who asked could not be found.'));

            return;
        }

        $input = $turn->prompt() ?? Decisions::from(collect($turn->decisions() ?? [])->map(
            fn (bool $approve) => $approve ? Decision::approve() : Decision::reject(Chat::rejectionResult()),
        )->all());

        $agent = Agents::agent($turn->context, $turn->model)->continue($turn->conversation_id, as: $user);
        $turns->snapshot($turn, null, __('Thinking…'));
        $store->answering($turn->message_id);

        $buffer = '';
        $stopped = false;

        try {
            $resolved = AgentModels::resolve($turn->model);
            $provider = app(AiManager::class)->textProviderFor($agent, $resolved['provider']);
            // What no longer fits the history window is folded into the rolling summary by the provider's cheapest model.
            $store->summarizeWith(AgentConversationStore::providerSummarizer($provider));
            $response = $agent->withModel($resolved['model'])->stream($input, provider: $resolved['provider'], model: $resolved['model']);

            $sinceWrite = 0;
            $lastCheck = 0.0;
            $status = __('Thinking…');

            // The answer so far is written on every line (or every ~120 chars) and on every tool event, so the page
            // can render it as Markdown while it streams; Stop is a flag on the row, read whenever a snapshot is
            // written and at least a few times a second. Leaving the stream on a stop means laravel/ai never stores
            // the answer, so the partial one is stored below, with a marker.
            foreach ($response as $event) {
                $wrote = false;

                if ($event instanceof TextDelta) {
                    $buffer .= $event->delta;
                    $sinceWrite += strlen($event->delta);
                    $status = __('Writing…');
                    if (str_contains($event->delta, "\n") || $sinceWrite >= 120) {
                        $turns->snapshot($turn, $buffer, $status);
                        $sinceWrite = 0;
                        $wrote = true;
                    }
                } elseif ($event instanceof ToolCall) {
                    $status = __(':tool…', ['tool' => Str::headline($event->toolCall->name)]);
                    $turns->snapshot($turn, $buffer, $status);
                    $wrote = true;
                } elseif ($event instanceof ToolResult) {
                    $status = __('Thinking…');
                    if ($buffer !== '') {
                        $buffer .= "\n\n";
                    }
                    $turns->snapshot($turn, $buffer, $status);
                    $wrote = true;
                } elseif ($event instanceof Error && ! $event->recoverable) {
                    throw new RuntimeException($event->message);
                }

                if ($wrote || microtime(true) - $lastCheck >= 0.25) {
                    $lastCheck = microtime(true);
                    if ($turns->stopRequested($turn)) {
                        $stopped = true;
                        break;
                    }
                }
            }

            if ($stopped) {
                if (trim($buffer) !== '') {
                    $store->storeStoppedAnswer($turn->conversation_id, $user, $agent::class, $buffer);
                }
                $turns->finish($turn, AgentTurn::STOPPED, text: $buffer);

                return;
            }

            if (($turn->input['title'] ?? false) && $turn->prompt() !== null) {
                $store->titleConversation($turn->conversation_id, $turn->prompt(), $provider);
            }

            $turns->finish($turn, AgentTurn::DONE, text: $buffer);
        } catch (Throwable $e) {
            report($e);
            $turns->finish($turn, AgentTurn::FAILED, $e->getMessage(), text: $buffer);
        } finally {
            // The store is a request-scoped singleton and must not carry a turn's state into the next one.
            $store->answering(null);
            $store->summarizeWith(null);
        }
    }
}
