<?php

namespace Packstub\Agents\Filament\Pages;

use BackedEnum;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Filament\FilamentContext;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentModels;
use Throwable;

/**
 * One conversation with the assistant: the panel's surface over the engine's
 * AgentChat. A question becomes a turn on the conversation; the RunAgentTurn
 * job produces the answer while the page polls the turn row, so the answer
 * keeps coming when the page is reloaded, reopened or open in a second tab,
 * and can be stopped. A proposed change shows up as a card with Approve /
 * Reject, and the decision is a turn of its own. Messages are read back from
 * the database on every render. This class holds what only the panel knows:
 * the URL, the poll route, the notifications and the redirects.
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

    protected ?AgentChat $chat = null;

    public static function canAccess(): bool
    {
        return AgentModels::enabled();
    }

    public function mount(?string $conversation = null): void
    {
        $this->model = AgentModels::current();
        $this->context = request()->query('context');

        if ($conversation) {
            abort_unless($this->chat()->owns($conversation), 404);
            $this->conversation = $conversation;
            $this->chat = null;
        }

        if ($prompt = session()->pull('packstub-agents.prompt') ?? request()->query('prompt')) {
            $this->prompt = (string) $prompt;
            $this->autoSend = true;
        }
    }

    /** The engine's chat for this person, conversation, model and context (one per request). */
    protected function chat(): AgentChat
    {
        return $this->chat ??= AgentChat::for(auth()->user(), $this->conversation, $this->model, $this->context);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->conversation ? (string) ($this->chat()->title() ?? __('Chat')) : __('New chat');
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function contextLabel(): ?string
    {
        return $this->chat()->contextLabel();
    }

    /** @return list<string> */
    public function suggestions(): array
    {
        return $this->chat()->suggestions();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function messages(): Collection
    {
        return $this->chat()->messages();
    }

    /** @return array{active: ?array, queued: list<array>, ended: ?array} */
    public function live(): array
    {
        return $this->chat()->live();
    }

    public function idle(): bool
    {
        return $this->chat()->idle();
    }

    /** Where the page polls the running turn (null before the first question of a new chat). */
    public function pollUrl(): ?string
    {
        if (! $this->conversation || ! ($panel = app(FilamentContext::class)->panel())) {
            return null;
        }

        return $panel->route('packstub-agents.turn', array_filter(['conversation' => $this->conversation, 'tenant' => Filament::getTenant()]));
    }

    /** @see AgentChat::history() */
    public function history(): ?array
    {
        return $this->chat()->history();
    }

    /** Fold the older part of this chat into its rolling summary now, keeping the last exchanges verbatim. */
    public function compressNow(): void
    {
        if (! $this->conversation || ! AgentModels::enabled() || ! $this->idle()) {
            return;
        }

        try {
            $compressed = $this->chat()->compress();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title(__('The chat could not be summarized'))->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title($compressed ? __('Older messages were compressed') : __('Nothing older to compress'))
            ->body($compressed ? __('The assistant reads a summary of them from the next question on.') : __('The last exchanges are always kept as they are.'))
            ->success()
            ->send();
    }

    /** Open a new chat that starts from a summary of this one, and go there. */
    public function continueInNewChat(): void
    {
        if (! $this->conversation || ! AgentModels::enabled()) {
            return;
        }

        try {
            $id = $this->chat()->continueInNew();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title(__('The chat could not be summarized'))->body($e->getMessage())->danger()->send();

            return;
        }

        if ($id !== null) {
            $this->redirect(static::getUrl(['conversation' => $id]));
        }
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

        return $this->started(fn (AgentChat $chat) => $chat->send($prompt), question: true);
    }

    public function decide(string $callId, bool $approve): ?array
    {
        return $this->started(fn (AgentChat $chat) => $chat->decide($callId, $approve));
    }

    /** Send the last question again when it never got an answer. */
    public function retry(): ?array
    {
        return $this->started(fn (AgentChat $chat) => $chat->retry(), question: true);
    }

    /** Answer the last question again: its answer is dropped and the same recorded question is sent once more. */
    public function regenerate(): ?array
    {
        return $this->started(fn (AgentChat $chat) => $chat->regenerate(), question: true);
    }

    /** Edit the last question and send it again: its answer is dropped, the recorded question rewritten. */
    public function resend(string $prompt): ?array
    {
        return $this->started(fn (AgentChat $chat) => $chat->resend($prompt), question: true);
    }

    /** Stop the running turn; the job stores what it has so far. */
    public function stop(): void
    {
        $this->chat()->stop();
    }

    /** Take a waiting question out of the line. */
    public function removeQueued(string $turn): void
    {
        $this->chat()->removeQueued($turn);
    }

    /** Take a waiting question out of the line and hand its text back to the composer. */
    public function editQueued(string $turn): ?string
    {
        return $this->chat()->editQueued($turn);
    }

    public function feedback(string $messageId, string $rating): void
    {
        $this->chat()->rate($messageId, $rating);
    }

    /**
     * Queue one turn through the engine and tell the page what to poll. A new chat keeps the page (queued
     * questions would not survive a reload) and takes the conversation's URL. On the sync driver the turn
     * already ran inside this request: a failure is said now, as the page did before.
     *
     * @param  Closure(AgentChat): ?AgentTurn  $start
     * @return array{turn: string, conversation: string, poll: ?string, active: bool}|null
     */
    protected function started(Closure $start, bool $question = false): ?array
    {
        if (! AgentModels::enabled()) {
            Notification::make()->title(__(':name is not connected to an AI provider yet.', ['name' => Agents::name()]))->warning()->send();

            return null;
        }

        $chat = $this->chat();
        $new = $chat->conversation() === null;
        $turn = $start($chat);

        if (! $turn) {
            return null;
        }

        $this->conversation = $chat->conversation();

        if ($new) {
            $this->js('window.history.replaceState({}, "", '.json_encode(static::getUrl(['conversation' => $this->conversation])).')');
        }

        if ($turn->status === AgentTurn::FAILED) {
            $kept = $question ? ' '.__('Your question is kept — use Retry to send it again.') : '';

            if ($turn->finish_reason === 'refused') {
                Notification::make()->title($turn->error)->body(trim($kept) ?: null)->warning()->send();
            } else {
                Notification::make()
                    ->title(__('The assistant could not answer'))
                    ->body($turn->error.$kept)
                    ->danger()
                    ->persistent()
                    ->send();
            }
        }

        return [
            'turn' => $turn->id,
            'conversation' => $this->conversation,
            'poll' => $this->pollUrl(),
            'active' => $turn->isActive(),
        ];
    }
}
