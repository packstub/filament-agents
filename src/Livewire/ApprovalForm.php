<?php

namespace Packstub\FilamentAgents\Livewire;

use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component as SchemaComponent;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Packstub\Agents\Chat\ChatView;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentModels;
use Packstub\FilamentAgents\Contracts\HasApprovalForm;

/**
 * The approval card's form for a tool with HasApprovalForm: the proposed arguments in the tool's own Filament fields,
 * validated by their rules on Approve, then the decision made on the server with the corrected arguments
 * (AgentChat::decide()). The chat around it is the engine's, without Livewire: it hears the decision through the
 * `agents-decided` browser event and follows the turn. Reject stays the chat's own button.
 *
 * The conversation and the call are locked and read again on every request, from the person's own paused answer:
 * a request from the browser cannot point the form at another person's proposal, and the arguments it starts from
 * are the stored ones, not the page's.
 *
 * Only the fields the person changed are sent as corrections. A field rewrites what it is filled with (a date picker
 * stores "2026-12-01T10:00:00Z" as "2026-12-01 10:00:00"), so an untouched form would otherwise count as a
 * correction and the tool would get the field's format in place of the model's.
 */
class ApprovalForm extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    #[Locked]
    public string $conversation = '';

    #[Locked]
    public string $call = '';

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * The form's state as it was filled from the proposal, to tell which fields the person changed.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $filled = [];

    public function mount(string $conversation, string $call): void
    {
        $this->conversation = $conversation;
        $this->call = $call;

        $this->form->fill($this->proposed()['arguments'] ?? []);
        $this->filled = $this->data;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->fields() ?? [])
            ->statePath('data');
    }

    /**
     * The fields a tool gives a proposed call, or null for the card's own Approve and Reject: a tool without
     * HasApprovalForm, no fields for these arguments, or a form that throws (reported, like a preview that throws,
     * so it never stands between the person and the decision).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<SchemaComponent>|null
     */
    public static function fieldsFor(string $tool, array $arguments): ?array
    {
        $tool = AgentChat::writeTools()->get($tool);

        if (! $tool instanceof HasApprovalForm) {
            return null;
        }

        return rescue(fn () => $tool->approvalForm($arguments), null) ?: null;
    }

    public function approve(): void
    {
        if ($why = AgentModels::unavailable()) {
            Notification::make()->title($why)->warning()->send();

            return;
        }

        if ($this->fields() === null) {
            $this->failed();

            return;
        }

        $data = $this->data;
        $corrected = array_filter(
            $this->form->getState(),
            fn (string $field) => ($data[$field] ?? null) !== ($this->filled[$field] ?? null),
            ARRAY_FILTER_USE_KEY,
        );

        $view = ChatView::make($this->conversation);
        $turn = $view->chat->decide($this->call, true, $corrected);

        if (! $turn) {
            $this->failed();

            return;
        }

        if ($turn->status === AgentTurn::FAILED) {
            $turn->finish_reason === 'refused'
                ? Notification::make()->title((string) $turn->error)->warning()->send()
                : Notification::make()->title(__('The assistant could not answer'))->body($turn->finish_reason === 'failed' ? null : (string) $turn->error)->danger()->persistent()->send();
        }

        $this->dispatch('agents-decided', turn: $view->turn($turn));
    }

    /**
     * The tool's fields for the call as it waits, or null once there is nothing to correct.
     *
     * @return array<SchemaComponent>|null
     */
    public function fields(): ?array
    {
        $proposed = $this->proposed();

        return $proposed ? self::fieldsFor($proposed['name'], $proposed['arguments']) : null;
    }

    /**
     * The call as it waits in the person's own paused answer, or null once it was decided (or is not theirs).
     *
     * @return array{name: string, arguments: array<string, mixed>}|null
     */
    protected function proposed(): ?array
    {
        $user = auth()->user();

        if (! $user || $this->conversation === '' || $this->call === '') {
            return null;
        }

        return app(AgentConversationStore::class)->pendingCalls($this->conversation, $user)[$this->call] ?? null;
    }

    protected function failed(): void
    {
        Notification::make()->title(__('The decision could not be applied.'))->warning()->send();
        $this->dispatch('agents-decided', turn: null);
    }

    public function render(): View
    {
        return view('packstub-agents::livewire.approval-form');
    }
}
