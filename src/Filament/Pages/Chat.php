<?php

namespace Packstub\Agents\Filament\Pages;

use BackedEnum;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Filament\FilamentContext;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentAttachments;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentModels;
use Packstub\Agents\Support\AgentResources;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * One conversation with the assistant: the panel's surface over the engine's
 * AgentChat. A question becomes a turn on the conversation; the RunAgentTurn
 * job produces the answer while the page listens to the turn's event stream
 * (and polls when it cannot), so the answer keeps coming when the page is
 * reloaded, reopened or open in a second tab, and can be stopped. A proposed
 * change shows up as a card with Approve / Reject, and the decision is a turn
 * of its own. Messages are read back from the database on every render.
 * This class holds what only the panel knows: the URL, the routes, the
 * uploads, the notifications and the redirects. With ?embedded=1 the page
 * renders without the panel's chrome, for the slide-over the "Ask …"
 * button opens over any page.
 */
class Chat extends Page
{
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'chat/{conversation?}';

    protected string $view = 'packstub-agents::pages.chat';

    public ?string $conversation = null;

    public string $prompt = '';

    public string $model = 'auto';

    public ?string $context = null;

    public bool $autoSend = false;

    /** The page rendered without the panel's chrome, inside the slide-over. */
    public bool $embedded = false;

    /** @var list<TemporaryUploadedFile> the files picked in the composer, sent with the next question */
    public array $attachments = [];

    /** The note typed under a thumbs-down, keyed by message id. */
    public array $feedbackNote = [];

    protected ?AgentChat $chat = null;

    public static function canAccess(): bool
    {
        return AgentModels::enabled();
    }

    public function mount(?string $conversation = null): void
    {
        $this->model = AgentModels::current();
        $this->context = request()->query('context');
        $this->embedded = (bool) request()->query('embedded');

        if ($conversation) {
            // Checked on a chat without the conversation: the engine refuses another person's conversation as not found.
            abort_unless(AgentChat::for(auth()->user())->owns($conversation), 404);
            $this->conversation = $conversation;
            $this->chat = null;
        }

        if ($prompt = session()->pull('packstub-agents.prompt') ?? request()->query('prompt')) {
            $this->prompt = (string) $prompt;
            $this->autoSend = true;
        }
    }

    /** Inside the slide-over the page has no sidebar, topbar or breadcrumbs. */
    public function getLayout(): string
    {
        return $this->embedded ? 'filament-panels::components.layout.base' : parent::getLayout();
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

    /** @return array{active: ?array, queued: list<array>, held: array, ended: ?array} */
    public function live(): array
    {
        return $this->chat()->live();
    }

    public function idle(): bool
    {
        return $this->chat()->idle();
    }

    public function pinned(): bool
    {
        return $this->chat()->pinned();
    }

    /** Where the page polls the running turn (null before the first question of a new chat). */
    public function pollUrl(): ?string
    {
        return $this->routeUrl('packstub-agents.turn');
    }

    /** Where the page listens for the running turn's events (the same state, pushed). */
    public function streamUrl(): ?string
    {
        return $this->routeUrl('packstub-agents.stream');
    }

    protected function routeUrl(string $name): ?string
    {
        if (! $this->conversation || ! ($panel = app(FilamentContext::class)->panel())) {
            return null;
        }

        return $panel->route($name, array_filter(['conversation' => $this->conversation, 'tenant' => Filament::getTenant()]));
    }

    /** The full-page URL of this chat (the slide-over links to it). */
    public function pageUrl(): string
    {
        return static::getUrl(array_filter(['conversation' => $this->conversation, 'context' => $this->conversation ? null : $this->context]));
    }

    /** @see AgentChat::history() */
    public function history(): ?array
    {
        return $this->chat()->history();
    }

    /**
     * What the composer's attach button accepts (null when attachments are off).
     *
     * @return array{accept: string, maxKb: int, maxFiles: int}|null
     */
    public function attachmentSettings(): ?array
    {
        if (! AgentAttachments::enabled()) {
            return null;
        }

        return ['accept' => implode(',', AgentAttachments::mimes()), 'maxKb' => AgentAttachments::maxKilobytes(), 'maxFiles' => AgentAttachments::maxPerQuestion()];
    }

    /** Whether "@" in the composer can offer records: the panel has resources the assistant knows. */
    public function canMention(): bool
    {
        return Agents::resourceClasses() !== [];
    }

    /**
     * The records "@…" in the composer offers: every agent resource of the panel searched on its globally searchable
     * attributes (or its title attribute), a few per resource, as ref ("orders/12"), label and resource name.
     *
     * @return list<array{ref: string, label: string, resource: string}>
     */
    public function searchRecords(string $query): array
    {
        $query = trim($query);
        $results = [];

        foreach (AgentResources::all() as $key => $resource) {
            if (! is_subclass_of($resource, Resource::class) || ! $resource::canViewAny()) {
                continue;
            }

            $attributes = array_values(array_filter($resource::getGloballySearchableAttributes() ?: array_filter([$resource::getRecordTitleAttribute()]), fn ($a) => is_string($a) && ! str_contains($a, '.')));

            if ($attributes === []) {
                continue;
            }

            $model = $resource::getModel();
            $records = $resource::getEloquentQuery()
                ->when($query !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($attributes, $query) {
                    foreach ($attributes as $attribute) {
                        $q->orWhere($attribute, 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%');
                    }
                }))
                ->orderByDesc((new $model)->getKeyName())
                ->limit($query === '' ? 3 : 5)
                ->get();

            foreach ($records as $record) {
                $results[] = ['ref' => $key.'/'.$record->getKey(), 'label' => $resource::agentContextLabel($record), 'resource' => (string) $resource::getPluralModelLabel()];
            }
        }

        return array_slice($results, 0, 12);
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

    /**
     * The composer passes the question along (agent-chat.js) with the records it mentions ("@Order RO-00012" picked
     * from the list: refs such as "orders/12"); $prompt on the component is the auto-sent one from the URL or session.
     * The files picked in the composer go with it.
     *
     * @param  list<string>  $mentions
     */
    public function send(?string $prompt = null, array $mentions = []): ?array
    {
        $prompt = trim($prompt ?? $this->prompt);
        $files = [];

        try {
            foreach (array_slice($this->attachments, 0, AgentAttachments::maxPerQuestion()) as $upload) {
                if ($upload instanceof TemporaryUploadedFile) {
                    $files[] = AgentAttachments::store($upload);
                }
            }
        } catch (InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();

            return null;
        }

        if ($prompt === '' && $files === []) {
            return null;
        }

        $this->prompt = '';
        $this->autoSend = false;
        $this->attachments = [];

        return $this->started(fn (AgentChat $chat) => $chat->send($prompt, $files, array_values(array_filter($mentions, 'is_string'))), question: true);
    }

    /** Take a picked file out of the composer before it is sent. */
    public function removeAttachment(int $index): void
    {
        $this->attachments = array_values(array_filter($this->attachments, fn ($_, int $i) => $i !== $index, ARRAY_FILTER_USE_BOTH));
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

    /** Answer the last question again: its answer is kept as a version and the same recorded question is sent once more. */
    public function regenerate(): ?array
    {
        return $this->started(fn (AgentChat $chat) => $chat->regenerate(), question: true);
    }

    /** Carry on an answer the model's length limit cut short. */
    public function continueAnswer(): ?array
    {
        return $this->started(fn (AgentChat $chat) => $chat->continueAnswer(), question: true);
    }

    /** Edit the last question and send it again: its answer is kept as a version, the recorded question rewritten. */
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

    /** Rate an answer; a thumbs-down may carry a note on what went wrong. */
    public function feedback(string $messageId, string $rating, ?string $note = null): void
    {
        $note ??= $this->feedbackNote[$messageId] ?? null;
        $this->chat()->rate($messageId, $rating, $rating === 'down' ? $note : null);
        unset($this->feedbackNote[$messageId]);
    }

    /**
     * The earlier answers to a question, for the version switcher.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function versions(string $questionId): Collection
    {
        return $this->chat()->versions($questionId);
    }

    /** Put an earlier answer to the last question back; the current one becomes a version in turn. */
    public function showVersion(string $questionId, int $versionId): void
    {
        if (! $this->chat()->showVersion($questionId, $versionId)) {
            Notification::make()->title(__('That answer cannot be shown right now.'))->warning()->send();
        }
    }

    /** Give the chat a title of your own. */
    public function rename(string $title): void
    {
        $this->chat()->rename($title);
    }

    public function togglePin(): void
    {
        $this->chat()->pinned() ? $this->chat()->unpin() : $this->chat()->pin();
    }

    /** The chat as a Markdown file. */
    public function export(): ?StreamedResponse
    {
        if (! $this->conversation) {
            return null;
        }

        $transcript = $this->chat()->transcript();
        $name = Str::slug(Str::limit((string) $this->getTitle(), 60, ''), '-') ?: 'chat';

        return response()->streamDownload(fn () => print $transcript, "{$name}.md", ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }

    /**
     * A read tool's call on one line of the timeline: its first scalar arguments ("query: acme · status: live").
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function callSummary(array $arguments): ?string
    {
        $parts = [];

        foreach (self::flatten($arguments) as $key => $value) {
            $parts[] = $key.': '.Str::limit((string) $value, 40);

            if (count($parts) === 3) {
                break;
            }
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** A read tool's result on the timeline line: how many it found, or what kind of thing it returned. */
    public static function resultSummary(mixed $result): ?string
    {
        $decoded = is_string($result) ? json_decode($result, true) : $result;

        if (! is_array($decoded)) {
            return is_string($result) && $result !== '' ? Str::limit($result, 60) : null;
        }

        if (isset($decoded['error']) && is_string($decoded['error'])) {
            return __('failed');
        }

        if (isset($decoded['chart'])) {
            return __('a chart');
        }

        if (isset($decoded['table'])) {
            return __('a table');
        }

        if (isset($decoded['total']) && is_numeric($decoded['total'])) {
            return trans_choice('{0} nothing found|{1} :count found|[2,*] :count found', (int) $decoded['total']);
        }

        foreach (['rows', 'items', 'results', 'records', 'data'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key]) && array_is_list($decoded[$key])) {
                return trans_choice('{0} nothing found|{1} :count found|[2,*] :count found', count($decoded[$key]));
            }
        }

        return null;
    }

    /** @return array<string, string> */
    protected static function flatten(array $arguments, string $prefix = ''): array
    {
        $flat = [];

        foreach ($arguments as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += array_is_list($value) ? [$name => implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $value))] : self::flatten($value, $name);
            } elseif (is_scalar($value) && (string) $value !== '') {
                $flat[$name] = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
            }
        }

        return $flat;
    }

    /**
     * Queue one turn through the engine and tell the page what to poll. A new chat keeps the page (queued
     * questions would not survive a reload) and takes the conversation's URL. On the sync driver the turn
     * already ran inside this request: a failure is said now, as the page did before.
     *
     * @param  Closure(AgentChat): ?AgentTurn  $start
     * @return array{turn: string, conversation: string, poll: ?string, stream: ?string, active: bool}|null
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

        if ($new && ! $this->embedded) {
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
            'stream' => $this->streamUrl(),
            'active' => $turn->isActive(),
        ];
    }
}
