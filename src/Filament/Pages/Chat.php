<?php

namespace Packstub\Agents\Filament\Pages;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Mcp\AgentTool;
use Packstub\Agents\Models\AgentMessageFeedback;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentBudget;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentModels;
use Packstub\Agents\Support\AgentResources;
use Packstub\Agents\Support\AgentTurns;
use Packstub\Agents\Support\PageContext;
use Throwable;

/**
 * One conversation with the assistant. A question becomes a turn on the
 * conversation (AgentTurns); the RunAgentTurn job produces the answer while
 * the page polls the turn row, so the answer keeps coming when the page is
 * reloaded, reopened or open in a second tab, and can be stopped. A proposed
 * change shows up as a card with Approve / Reject, and the decision is a
 * turn of its own. Messages are read back from the database on every render.
 */
class Chat extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'chat/{conversation?}';

    protected string $view = 'packstub-agents::pages.chat';

    public ?string $conversation = null;

    public string $prompt = '';

    public string $model = 'auto';

    public ?string $context = null;

    public bool $autoSend = false;

    /** @var array{active: ?array, queued: list<array>, ended: ?array}|null */
    protected ?array $live = null;

    public static function canAccess(): bool
    {
        return AgentModels::enabled();
    }

    public function mount(?string $conversation = null): void
    {
        $this->model = AgentModels::current();
        $this->context = request()->query('context');

        if ($conversation) {
            abort_unless($this->ownConversations()->whereKey($conversation)->exists(), 404);
            $this->conversation = $conversation;
        }

        if ($prompt = session()->pull('packstub-agents.prompt') ?? request()->query('prompt')) {
            $this->prompt = (string) $prompt;
            $this->autoSend = true;
        }
    }

    public function getTitle(): string|Htmlable
    {
        return $this->conversation
            ? (string) ($this->ownConversations()->whereKey($this->conversation)->value('title') ?? __('Chat'))
            : __('New chat');
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function contextLabel(): ?string
    {
        return PageContext::resolve($this->context)['label'] ?? null;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function messages(): Collection
    {
        if (! $this->conversation) {
            return collect();
        }

        $feedback = AgentMessageFeedback::query()->where('user_id', auth()->id())->pluck('rating', 'message_id');
        $writeTools = self::writeToolNames();
        $idle = $this->idle();

        $list = ConversationMessage::query()
            ->where('conversation_id', $this->conversation)
            ->orderBy('created_at')
            ->orderByRaw("case when role = 'user' then 0 else 1 end") // a question and its answer can share a second
            ->orderBy('id')
            ->get()
            ->map(function (ConversationMessage $m) use ($feedback, $writeTools) {
                $results = collect($m->tool_results ?? [])->keyBy('id');
                $everPaused = collect($m->approval_state['pending'] ?? [])->keys();
                $pending = $everPaused->reject(fn ($id) => $results->has($id));
                $charts = $results->map(fn ($r) => self::chartFromResult($r['result'] ?? null))->filter()->values()->all();
                $tables = $results->map(fn ($r) => self::tableFromResult($r['result'] ?? null))->filter()->values()->all();

                return [
                    'id' => $m->id,
                    'role' => $m->role,
                    'text' => (string) $m->content,
                    'html' => $m->role === 'assistant' ? self::markdown((string) $m->content) : e((string) $m->content),
                    // A write tool stays a card (proposal / done / rejected) after the decision, when the paused list is empty again.
                    'tools' => collect($m->tool_calls ?? [])->map(fn ($call) => [
                        'id' => $call['id'] ?? null,
                        'name' => Str::headline((string) ($call['name'] ?? '')),
                        'arguments' => $call['arguments'] ?? [],
                        'pending' => $pending->contains($call['id'] ?? null),
                        'result' => $results->get($call['id'] ?? null)['result'] ?? null,
                        'rejected' => (bool) ($results->get($call['id'] ?? null)['denied'] ?? false),
                        'readOnly' => ! in_array($call['name'] ?? '', $writeTools, true) && ! $everPaused->contains($call['id'] ?? null),
                    ])->values()->all(),
                    'charts' => $charts,
                    'tables' => $tables,
                    'rating' => $feedback->get($m->id),
                    'at' => $m->created_at,
                    'stopped' => AgentConversationStore::wasStopped($m->meta),
                    'cutShort' => AgentConversationStore::cutShort($m->meta),
                    'unanswered' => false,
                    'editable' => false,
                    'regenerable' => false,
                ];
            });

        if ($list->isEmpty()) {
            return $list;
        }

        // The last exchange: the last question can be edited and sent again, its answer produced again — while nothing runs.
        $lastQuestion = null;
        for ($i = $list->count() - 1; $i >= 0; $i--) {
            if ($list[$i]['role'] === 'user') {
                $lastQuestion = $i;
                break;
            }
        }

        if ($idle && $lastQuestion !== null) {
            $list->put($lastQuestion, [...$list[$lastQuestion], 'editable' => true]);
        }

        $last = $list->last();

        if ($last['role'] === 'user') {
            // A question with nothing after it was recorded but not answered: while a turn runs it is being answered,
            // otherwise the provider failed or the person stopped it and it gets a Retry.
            $list->push([...$list->pop(), 'unanswered' => $idle]);
        } elseif ($idle && ! collect($last['tools'])->contains('pending', true)) {
            $list->push([...$list->pop(), 'regenerable' => true]);
        }

        return $list;
    }

    /**
     * The turn that runs on this conversation, the questions waiting behind it, and how the last turn ended
     * when the last question has no answer.
     *
     * @return array{active: ?array{id: string, status: string, statusText: string, html: string}, queued: list<array{id: string, text: string}>, ended: ?array{status: string, error: ?string}}
     */
    public function live(): array
    {
        if ($this->live !== null) {
            return $this->live;
        }

        if (! $this->conversation) {
            return $this->live = ['active' => null, 'queued' => [], 'ended' => null];
        }

        $turns = app(AgentTurns::class);
        $turns->reconcile($this->conversation);
        $active = $turns->active($this->conversation);
        $latest = $turns->latest($this->conversation);

        return $this->live = [
            'active' => $active ? [
                'id' => $active->id,
                'status' => $active->status,
                'statusText' => $active->status_text ?? __('Thinking…'),
                'html' => filled($active->text) ? self::markdown((string) $active->text) : '',
            ] : null,
            'queued' => $turns->queued($this->conversation)->map(fn (AgentTurn $t) => ['id' => $t->id, 'text' => (string) $t->prompt()])->values()->all(),
            'ended' => $latest && in_array($latest->status, [AgentTurn::FAILED, AgentTurn::STOPPED], true) ? ['status' => $latest->status, 'error' => $latest->error] : null,
        ];
    }

    /** Nothing runs or waits on this conversation. */
    public function idle(): bool
    {
        $live = $this->live();

        return $live['active'] === null && $live['queued'] === [];
    }

    /** Where the page polls the running turn (null before the first question of a new chat). */
    public function pollUrl(): ?string
    {
        if (! $this->conversation || ! ($panel = Agents::panel())) {
            return null;
        }

        return $panel->route('packstub-agents.turn', array_filter(['conversation' => $this->conversation, 'tenant' => Filament::getTenant()]));
    }

    /**
     * How full the history window is, for the meter under the transcript (page context is the $context property).
     *
     * @return array{tokens: int, budget: int, share: float, summarized: bool, source: ?string, sourceTitle: ?string, notice: bool}|null
     */
    public function history(): ?array
    {
        if (! $this->conversation) {
            return null;
        }

        $usage = app(AgentConversationStore::class)->contextUsage($this->conversation);

        return [
            ...$usage,
            'sourceTitle' => $usage['source'] ? $this->ownConversations()->whereKey($usage['source'])->value('title') : null,
            'notice' => $usage['share'] >= (float) config('packstub-agents.history.notice_share', 0.7),
        ];
    }

    /** Open a new chat that starts from a summary of this one, and go there. */
    public function continueInNewChat(): void
    {
        if (! $this->conversation || ! AgentModels::enabled()) {
            return;
        }

        $resolved = AgentModels::resolve($this->model);
        $agent = Agents::agent($this->context, $this->model);
        $provider = app(AiManager::class)->textProviderFor($agent, $resolved['provider']);
        $title = Str::limit((string) $this->ownConversations()->whereKey($this->conversation)->value('title'), 80);

        try {
            $id = app(AgentConversationStore::class)->continueConversation(
                $this->conversation,
                auth()->user(),
                __(':title (continued)', ['title' => $title]),
                AgentConversationStore::providerSummarizer($provider),
            );
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title(__('The chat could not be summarized'))->body($e->getMessage())->danger()->send();

            return;
        }

        $this->redirect(static::getUrl(['conversation' => $id]));
    }

    /** The composer passes the question along (agent-chat.js); $prompt on the component is the auto-sent one from the URL or session. */
    public function send(?string $prompt = null): ?array
    {
        $prompt = trim($prompt ?? $this->prompt);
        if ($prompt === '') {
            return null;
        }

        $this->prompt = '';
        $this->autoSend = false;

        return $this->startTurn(['prompt' => $prompt]);
    }

    public function decide(string $callId, bool $approve): ?array
    {
        if (! $this->conversation || ! $this->idle()) {
            return null;
        }

        return $this->startTurn(['decisions' => [$callId => $approve]]);
    }

    /** Send the last question again when it never got an answer. */
    public function retry(): ?array
    {
        $last = $this->lastQuestion();

        if (! $last || ! $this->idle() || ConversationMessage::query()->where('conversation_id', $this->conversation)->where('id', '>', $last->id)->exists()) {
            return null;
        }

        return $this->startTurn(['prompt' => (string) $last->content], answering: $last->id);
    }

    /** Answer the last question again: its answer is dropped and the same recorded question is sent once more. */
    public function regenerate(): ?array
    {
        $last = $this->lastQuestion();

        if (! $last || ! $this->idle()) {
            return null;
        }

        app(AgentConversationStore::class)->dropMessagesAfter($this->conversation, $last->id);

        return $this->startTurn(['prompt' => (string) $last->content], answering: $last->id);
    }

    /** Edit the last question and send it again: its answer is dropped, the recorded question rewritten. */
    public function resend(string $prompt): ?array
    {
        $prompt = trim($prompt);
        $last = $this->lastQuestion();

        if ($prompt === '' || ! $last || ! $this->idle()) {
            return null;
        }

        if ($refusal = AgentBudget::refusal($prompt)) {
            Notification::make()->title($refusal)->warning()->send();

            return null;
        }

        $store = app(AgentConversationStore::class);
        $store->dropMessagesAfter($this->conversation, $last->id);
        $store->rewriteQuestion($this->conversation, $last->id, $prompt);

        return $this->startTurn(['prompt' => $prompt], answering: $last->id);
    }

    /** Stop the running turn; the job stores what it has so far. */
    public function stop(): void
    {
        if (! $this->conversation) {
            return;
        }

        $turns = app(AgentTurns::class);

        if ($active = $turns->active($this->conversation)) {
            $turns->requestStop($active);
        }
    }

    /** Take a waiting question out of the line. */
    public function removeQueued(string $turn): void
    {
        if ($queued = $this->queuedTurn($turn)) {
            app(AgentTurns::class)->remove($queued);
            $this->live = null;
        }
    }

    /** Take a waiting question out of the line and hand its text back to the composer. */
    public function editQueued(string $turn): ?string
    {
        if (! ($queued = $this->queuedTurn($turn)) || ! app(AgentTurns::class)->remove($queued)) {
            return null;
        }

        $this->live = null;

        return $queued->prompt();
    }

    /**
     * What the model reads in place of the tool's result when the person rejects
     * a proposal. A bare rejection would end the turn silently; with a reason
     * laravel/ai carries on, so the model can acknowledge and offer the next step.
     */
    public static function rejectionResult(): string
    {
        return 'The person rejected this change, so it did not run. Do not retry it or propose it again unless asked; acknowledge in one sentence and, if useful, ask what they would like instead.';
    }

    /**
     * The names of the tools that change data (the ones the chat wraps for approval), whatever the current role.
     *
     * @return list<string>
     */
    public static function writeToolNames(): array
    {
        return collect(Agents::toolClasses())
            ->map(fn (string $class) => app($class))
            ->reject(fn ($tool) => $tool instanceof AgentTool ? $tool->isReadOnly() : AgentTool::hasReadOnlyAnnotation($tool))
            ->map(fn ($tool) => $tool->name())
            ->values()
            ->all();
    }

    public function feedback(string $messageId, string $rating): void
    {
        AgentMessageFeedback::query()->updateOrCreate(
            ['message_id' => $messageId, 'user_id' => auth()->id()],
            ['rating' => $rating === 'up' ? 'up' : 'down'],
        );
    }

    /**
     * Queue one turn — a question or a set of approval decisions — on the conversation.
     *
     * The budget is checked here, in the request, so the person hears about it at once. A question is recorded
     * in the transcript when its turn starts; $answering names an already recorded question (a retry, a
     * regenerate, an edit). What comes back tells the page what to poll.
     *
     * @return array{turn: string, conversation: string, poll: ?string, active: bool}|null
     */
    protected function startTurn(array $input, ?string $answering = null): ?array
    {
        if (! AgentModels::enabled()) {
            Notification::make()->title(__(':name is not connected to an AI provider yet.', ['name' => Agents::name()]))->warning()->send();

            return null;
        }

        $prompt = $input['prompt'] ?? null;

        if ($refusal = AgentBudget::refusal($prompt)) {
            Notification::make()->title($refusal)->warning()->send();

            return null;
        }
        AgentBudget::hit();

        AgentModels::remember($this->model);
        $user = auth()->user();
        $store = app(AgentConversationStore::class);
        $started = null;

        if (! $this->conversation) {
            if ($prompt === null) {
                return null;
            }

            $this->conversation = $started = $store->startConversation($user, $prompt);
        }

        // A new chat is titled by the provider once its first answer is in (the question is the title until then).
        if ($prompt !== null && ! ConversationMessage::query()->where('conversation_id', $this->conversation)->where('role', 'assistant')->exists()) {
            $input['title'] = true;
        }

        $this->live = null;
        $turn = app(AgentTurns::class)->enqueue($this->conversation, $user, $input, $answering, $this->model, $this->context);

        // A new chat keeps the page (queued questions would not survive a reload) and takes the conversation's URL.
        if ($started !== null) {
            $this->js('window.history.replaceState({}, "", '.json_encode(static::getUrl(['conversation' => $started])).')');
        }

        // On the sync driver the turn already ran inside this request: say so now, as the page did before.
        if ($turn->status === AgentTurn::FAILED) {
            Notification::make()
                ->title(__('The assistant could not answer'))
                ->body($turn->error.($prompt !== null ? ' '.__('Your question is kept — use Retry to send it again.') : ''))
                ->danger()
                ->persistent()
                ->send();
        }

        return [
            'turn' => $turn->id,
            'conversation' => $this->conversation,
            'poll' => $this->pollUrl(),
            'active' => $turn->isActive(),
        ];
    }

    /** What to tell the person about an answer the provider ended early (AgentTurns::cutShortReason). */
    public static function cutShortText(string $reason): string
    {
        return match ($reason) {
            'length' => __('The answer hit the model\'s length limit.'),
            'content_filter' => __('The provider\'s content filter stopped the answer.'),
            default => __('The provider closed the stream before the answer was complete.'),
        };
    }

    /** The last question of the conversation. */
    protected function lastQuestion(): ?ConversationMessage
    {
        if (! $this->conversation) {
            return null;
        }

        return ConversationMessage::query()->where('conversation_id', $this->conversation)->where('role', 'user')->orderByDesc('id')->first();
    }

    protected function queuedTurn(string $id): ?AgentTurn
    {
        if (! $this->conversation) {
            return null;
        }

        return AgentTurn::query()->whereKey($id)->forConversation($this->conversation)->where('status', AgentTurn::QUEUED)->first();
    }

    /**
     * A tool result carrying a `chart` key becomes a Chart.js payload for the
     * same Alpine component Filament's chart widgets use.
     *
     * @return array{type: string, title: string, data: array<string, mixed>}|null
     */
    public static function chartFromResult(mixed $result): ?array
    {
        $decoded = is_string($result) ? json_decode($result, true) : $result;
        $chart = is_array($decoded) ? ($decoded['chart'] ?? null) : null;
        if (! is_array($chart) || empty($chart['labels']) || empty($chart['datasets'])) {
            return null;
        }

        $palette = ['#f59e0b', '#8b5cf6', '#10b981', '#3b82f6', '#ef4444', '#14b8a6', '#f97316', '#6366f1'];
        $type = in_array($chart['type'] ?? 'bar', ['bar', 'line', 'pie', 'doughnut'], true) ? $chart['type'] : 'bar';
        $circular = in_array($type, ['pie', 'doughnut'], true);

        $datasets = collect($chart['datasets'])->values()->map(function (array $d, int $i) use ($palette, $type, $circular, $chart) {
            $color = $palette[$i % count($palette)];

            return array_filter([
                'label' => (string) ($d['label'] ?? ''),
                'data' => array_values($d['data'] ?? []),
                'backgroundColor' => $circular ? array_map(fn ($j) => $palette[$j % count($palette)], array_keys($chart['labels'])) : ($type === 'line' ? $color.'22' : $color.'cc'),
                'borderColor' => $circular ? '#ffffff' : $color,
                'fill' => $type === 'line' ? true : null,
                'tension' => $type === 'line' ? 0.3 : null,
            ], fn ($v) => $v !== null);
        })->all();

        return [
            'type' => $type,
            'title' => (string) ($chart['title'] ?? ''),
            'data' => ['labels' => array_values($chart['labels']), 'datasets' => $datasets],
        ];
    }

    /**
     * A show-table result becomes an embedded resource table (AgentTable).
     *
     * @return array{resource: string, filters: array<string, mixed>, title: string}|null
     */
    public static function tableFromResult(mixed $result): ?array
    {
        $decoded = is_string($result) ? json_decode($result, true) : $result;
        $table = is_array($decoded) ? ($decoded['table'] ?? null) : null;
        if (! is_array($table) || ! AgentResources::has((string) ($table['resource'] ?? ''))) {
            return null;
        }

        return ['resource' => $table['resource'], 'filters' => (array) ($table['filters'] ?? []), 'title' => (string) ($table['title'] ?? '')];
    }

    public static function markdown(string $text): string
    {
        return Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    protected function ownConversations()
    {
        return Conversation::query()
            ->where('participant_type', auth()->user()?->getMorphClass())
            ->where('participant_id', auth()->id());
    }
}
