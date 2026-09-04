<?php

namespace Packstub\Agents\Filament\Pages;

use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Packstub\Agents\Mcp\AgentTool;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Models\AgentMessageFeedback;
use Packstub\Agents\Support\AgentBudget;
use Packstub\Agents\Support\AgentModels;
use Packstub\Agents\Support\AgentResources;
use Packstub\Agents\Support\PageContext;
use Throwable;

/**
 * One conversation with the assistant. The answer streams into the page over
 * Livewire (wire:stream) while the agent calls tools; a proposed change shows
 * up as a card with Approve / Reject, and the turn resumes with the decision.
 * Messages are read back from the database on every render, so a reload
 * never loses anything.
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

        return ConversationMessage::query()
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
                ];
            });
    }

    public function send(): void
    {
        $prompt = trim($this->prompt);
        if ($prompt === '') {
            return;
        }

        $this->prompt = '';
        $this->autoSend = false;
        $this->runTurn($prompt);
    }

    public function decide(string $callId, bool $approve): void
    {
        $this->runTurn(Decisions::from([$callId => $approve ? Decision::approve() : Decision::reject(self::rejectionResult())]));
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

    /** Stream one turn — a question or a set of approval decisions — into the page. */
    protected function runTurn(Decisions|string $input): void
    {
        if (! AgentModels::enabled()) {
            Notification::make()->title(__(':name is not connected to an AI provider yet.', ['name' => Agents::name()]))->warning()->send();

            return;
        }

        if ($refusal = AgentBudget::refusal(is_string($input) ? $input : null)) {
            Notification::make()->title($refusal)->warning()->send();

            return;
        }
        AgentBudget::hit();

        AgentModels::remember($this->model);
        $user = auth()->user();
        $agent = Agents::agent($this->context, $this->model);
        $agent = $this->conversation ? $agent->continue($this->conversation, as: $user) : $agent->forUser($user);

        if (is_string($input)) {
            $this->emit('pending-user', e($input), replace: true);
        }
        $this->emit('status', e(__('Thinking…')), replace: true);

        try {
            $resolved = AgentModels::resolve($this->model);
            $response = $agent->withModel($resolved['model'])->stream($input, provider: $resolved['provider'], model: $resolved['model']);

            // The answer is re-rendered as Markdown while it streams (on every line, or every ~120 chars),
            // so tables, lists and links take shape as they arrive instead of showing raw syntax.
            $buffer = '';
            $sinceRender = 0;

            foreach ($response as $event) {
                if ($event instanceof TextDelta) {
                    $buffer .= $event->delta;
                    $sinceRender += strlen($event->delta);
                    if (str_contains($event->delta, "\n") || $sinceRender >= 120) {
                        $this->emit('status', e(__('Writing…')), replace: true);
                        $this->emit('answer', self::markdown($buffer), replace: true);
                        $sinceRender = 0;
                    }
                } elseif ($event instanceof ToolCall) {
                    $this->emit('status', e(__(':tool…', ['tool' => Str::headline($event->toolCall->name)])), replace: true);
                } elseif ($event instanceof ToolResult) {
                    $this->emit('status', e(__('Thinking…')), replace: true);
                    if ($buffer !== '') {
                        $buffer .= "\n\n";
                    }
                } elseif ($event instanceof Error && ! $event->recoverable) {
                    throw new \RuntimeException($event->message);
                }
            }
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title(__('The assistant could not answer'))->body($e->getMessage())->danger()->persistent()->send();
        }

        $id = $agent->currentConversation();

        if ($id && $id !== $this->conversation) {
            $this->redirect(static::getUrl(['conversation' => $id]));

            return;
        }

        // Same conversation: the re-render reads the persisted messages, so the stream buffers are cleared.
        $this->emit('pending-user', '', replace: true);
        $this->emit('answer', '', replace: true);
        $this->emit('status', '', replace: true);
    }

    /** Livewire streaming needs a real HTTP response; in tests the deltas are simply dropped. */
    protected function emit(string $to, string $content, bool $replace = false): void
    {
        if (! app()->runningUnitTests()) {
            $this->stream(to: $to, content: $content, replace: $replace);
        }
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
