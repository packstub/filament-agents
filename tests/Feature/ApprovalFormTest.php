<?php

use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Packstub\Agents\Models\AgentToolCall;
use Packstub\Agents\Models\AgentTurn;
use Packstub\FilamentAgents\Contracts\HasApprovalForm;
use Packstub\FilamentAgents\Livewire\ApprovalForm;
use Packstub\FilamentAgents\Tests\Fixtures\Tools\RenameWidget;
use Packstub\FilamentAgents\Tests\Fixtures\WidgetAgent;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

// A write tool with HasApprovalForm (#108): its proposal shows the tool's Filament fields on the card, Approve
// validates them and runs the tool with the corrected arguments.
beforeEach(function () {
    // RenameWidget as the panel resolves it, with a form: the name may be corrected, and must not be empty.
    app()->bind(RenameWidget::class, fn () => new class extends RenameWidget implements HasApprovalForm
    {
        protected string $name = 'rename-widget';

        public function approvalForm(array $arguments): array
        {
            return [TextInput::make('name')->label('New name')->required()->maxLength(40)];
        }
    });
});

/** A chat of $user whose answer proposes renaming $widget to $name (call c1), paused for the decision. */
function proposedRename(object $user, object $widget, string $name): string
{
    $conversation = Conversation::query()->create(['id' => (string) Str::uuid(), 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id, 'title' => 'Rename']);
    $row = ['conversation_id' => $conversation->id, 'participant_type' => $user->getMorphClass(), 'participant_id' => $user->id, 'agent' => WidgetAgent::class, 'attachments' => [], 'meta' => [], 'usage' => []];
    ConversationMessage::query()->create(legacyRow($row + ['id' => (string) Str::uuid7(), 'role' => 'user', 'content' => 'Rename Alpha.', 'tool_calls' => [], 'tool_results' => [], 'created_at' => now()->subMinute()]));
    ConversationMessage::query()->create(legacyRow($row + ['id' => (string) Str::uuid7(), 'role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['id' => 'c1', 'name' => 'rename-widget', 'arguments' => ['id' => $widget->id, 'name' => $name]],
    ], 'tool_results' => [], 'approval_state' => ['pending' => ['c1' => 'Rename?']], 'created_at' => now()]));

    return $conversation->id;
}

it('shows the tool\'s form on the card in place of the Approve button', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    $conversation = proposedRename($user, $alpha, 'Alpah II');

    $html = chat(['conversation' => $conversation])->html();

    expect($html)->toContain('fi-chat-proposal-form', 'fi-chat-approval-form', 'New name', 'wire:id')
        ->toContain('decide(&#039;c1&#039;, false)') // Reject stays the chat's own decision
        ->not->toContain('decide(&#039;c1&#039;, true)');
});

it('validates the corrections and runs the tool with them', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();
    $conversation = proposedRename($user, $alpha, 'Alpah II');

    $form = livewire(ApprovalForm::class, ['conversation' => $conversation, 'call' => 'c1'])
        ->assertSet('data.name', 'Alpah II');

    // An empty name fails the field's own rule: nothing is decided.
    $form->set('data.name', '')->call('approve')->assertHasErrors(['data.name' => 'required'])->assertNotDispatched('agents-decided');
    expect(AgentTurn::query()->count())->toBe(0);

    WidgetAgent::fake(['Renamed to Alpha II.']);
    $form->set('data.name', 'Alpha II')->call('approve')->assertHasNoErrors()
        ->assertDispatched('agents-decided', fn (string $name, array $params) => $params['turn']['conversation'] === $conversation && $params['turn']['active'] === false);

    $row = AgentToolCall::query()->sole();
    expect($alpha->fresh()->name)->toBe('Alpha II')
        ->and($row->arguments['name'])->toBe('Alpah II')
        ->and($row->approved_arguments['name'])->toBe('Alpha II');
});

it('never decides another person\'s proposal', function () {
    [$alpha] = $this->widgets();
    $owner = $this->user();
    $conversation = proposedRename($owner, $alpha, 'Alpha II');
    actingAs($this->user());

    livewire(ApprovalForm::class, ['conversation' => $conversation, 'call' => 'c1'])
        ->assertSet('data.name', null)
        ->set('data.name', 'Hijacked')
        ->call('approve')
        ->assertNotified(__('The decision could not be applied.'));

    expect(AgentTurn::query()->count())->toBe(0)
        ->and($alpha->fresh()->name)->toBe('Alpha');
});
