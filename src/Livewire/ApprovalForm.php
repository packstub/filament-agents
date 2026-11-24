<?php

namespace Packstub\FilamentAgents\Livewire;

use Filament\Notifications\Notification;
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

    public function mount(string $conversation, string $call): void
    {
        $this->conversation = $conversation;
        $this->call = $call;

        $this->form->fill($this->proposed()['arguments'] ?? []);
    }

    public function form(Schema $schema): Schema
    {
        $proposed = $this->proposed();
        $tool = $proposed ? $this->tool($proposed['name']) : null;

        return $schema
            ->components($tool ? $tool->approvalForm($proposed['arguments']) : [])
            ->statePath('data');
    }

    public function approve(): void
    {
        if ($why = AgentModels::unavailable()) {
            Notification::make()->title($why)->warning()->send();

            return;
        }

        $proposed = $this->proposed();

        if (! $proposed || ! $this->tool($proposed['name'])) {
            $this->failed();

            return;
        }

        $view = ChatView::make($this->conversation);
        $turn = $view->chat->decide($this->call, true, $this->form->getState());

        if (! $turn) {
            $this->failed();

            return;
        }

        if ($turn->status === AgentTurn::FAILED) {
            $turn->finish_reason === 'refused'
                ? Notification::make()->title((string) $turn->error)->warning()->send()
                : Notification::make()->title(__('The assistant could not answer'))->body($turn->finish_reason === 'failed' ? null : (string) $turn->error)->danger()->persistent()->send();
        }

        $this->dispatch('agents-decided', turn: [
            'id' => $turn->id,
            'conversation' => $this->conversation,
            'poll' => $view->conversationUrl('turn'),
            'stream' => $view->conversationUrl('stream'),
            'active' => $turn->isActive(),
        ]);
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

    protected function tool(string $name): ?HasApprovalForm
    {
        $tool = AgentChat::writeTools()->get($name);

        return $tool instanceof HasApprovalForm ? $tool : null;
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
