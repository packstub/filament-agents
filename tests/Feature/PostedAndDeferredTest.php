<?php

use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Models\ConversationMessage;
use Packstub\Agents\Filament\Pages\Chat;
use Packstub\Agents\Filament\Pages\TurnLog;
use Packstub\Agents\Jobs\RunAgentTurn;
use Packstub\Agents\Models\AgentLimit;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentLimits;
use Packstub\Agents\Support\AgentTurns;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

it('starts a deferred first turn when its owner opens the chat, once, on the panel and under the budget of that moment', function () {
    $user = $this->user();
    $store = app(AgentConversationStore::class);
    $turns = app(AgentTurns::class);

    // The app, on a schedule and signed in as nobody, opens the chat and stores what it asks.
    $conversation = $store->startConversation($user, 'Let us close September.', 'September close');
    $deferred = $turns->defer($conversation, $user, ['prompt' => 'Let us close September.'], 'auto', null);
    expect($deferred->status)->toBe(AgentTurn::DEFERRED);

    // Someone else cannot open it; nothing starts.
    actingAs($this->user());
    livewire(Chat::class, ['conversation' => $conversation])->assertNotFound();
    expect($deferred->fresh()->status)->toBe(AgentTurn::DEFERRED);

    // The owner opens it: the turn goes to the queue as this request — the panel, the guard, the person — and the page
    // attaches to it, the question shown with no Retry.
    actingAs($user);
    Queue::fake();
    $page = livewire(Chat::class, ['conversation' => $conversation])
        ->assertSee('Let us close September.')
        ->assertDontSee(__('Retry'))
        ->assertSee('data-active="'.$deferred->id.'"', escape: false);

    expect($deferred->fresh()->status)->toBe(AgentTurn::PENDING)
        ->and($page->instance()->live()['active']['id'])->toBe($deferred->id)
        ->and($page->instance()->live()['deferred'])->toBeNull();
    Queue::assertPushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $deferred->id
        && $job->runtime === ['panel' => 'admin', 'guard' => 'web', 'tenant' => null, 'user' => $user->id, 'locale' => 'en']);

    // A second tab, or a reload, starts nothing more.
    livewire(Chat::class, ['conversation' => $conversation]);
    Queue::assertPushed(RunAgentTurn::class, 1);

    WidgetAgent::fake(['September is ready to close.']);
    Queue::pushed(RunAgentTurn::class)->first()->handle($turns);
    expect($deferred->fresh()->status)->toBe(AgentTurn::DONE)
        ->and(ConversationMessage::query()->where('conversation_id', $conversation)->orderBy('id')->pluck('role')->all())->toBe(['user', 'assistant']);
    livewire(Chat::class, ['conversation' => $conversation])->assertSee('September is ready to close.');

    // Another conversation deferred for them, opened when the workspace's answers for the day are used up: the budget is
    // checked now, the turn stays deferred with the reason under the question, and the next open tries again.
    $other = $store->startConversation($user, 'And October?', 'October close');
    $waiting = $turns->defer($other, $user, ['prompt' => 'And October?'], 'auto', null);
    AgentLimit::query()->create(['scope' => 'global', 'turns_per_day' => 1]);
    AgentLimits::flush();

    $refusal = __('This workspace reached today\'s limit of :n answers. It resets at midnight.', ['n' => 1]);
    $page = livewire(Chat::class, ['conversation' => $other])
        ->assertSee('And October?')
        ->assertSee($refusal)
        ->assertSee(__('It will be answered the next time you open this chat.'))
        ->assertDontSee(__('Retry'));
    expect($waiting->fresh())->toMatchArray(['status' => AgentTurn::DEFERRED, 'error' => $refusal])
        ->and($page->instance()->live()['deferred'])->toBe(['id' => $waiting->id, 'error' => $refusal])
        ->and($page->instance()->idle())->toBeFalse();
    Queue::assertPushed(RunAgentTurn::class, 1);

    // The operator sees it waiting.
    actingAs($this->user(['is_admin' => true]));
    livewire(TurnLog::class)
        ->filterTable('status', AgentTurn::DEFERRED)
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$deferred])
        ->assertSee(__('Deferred'));

    AgentLimit::query()->delete();
    AgentLimits::flush();
    actingAs($user);
    livewire(Chat::class, ['conversation' => $other])->assertDontSee($refusal);
    expect($waiting->fresh()->status)->toBe(AgentTurn::PENDING)->and($waiting->fresh()->error)->toBeNull();
    Queue::assertPushed(RunAgentTurn::class, 2);
});

it('shows a message the app posted as an answer, marked posted, with nothing to produce again', function () {
    $user = $this->user();
    $store = app(AgentConversationStore::class);

    $conversation = $store->startConversation($user, 'Digest', 'Monday digest');
    $messageId = $store->storePostedMessage($conversation, $user, "**Two invoices** are due this week.\n\nShall I draft the reminders?");

    actingAs($user);
    $page = livewire(Chat::class, ['conversation' => $conversation])
        ->assertSee('Monday digest')
        ->assertSee('Two invoices')
        ->assertSee(__('(posted)'))
        ->assertDontSee(__('(stopped)'))
        ->assertDontSee(__('Retry'))
        ->assertDontSee(__('Regenerate'));

    expect($page->instance()->messages()->sole())->toMatchArray(['id' => $messageId, 'posted' => true, 'regenerable' => false, 'unanswered' => false])
        ->and($page->instance()->idle())->toBeTrue();

    // The reply reads it: the model's answer follows, and the posted one is still not an answer to produce again.
    WidgetAgent::fake(['Drafting them now.']);
    $page->call('send', 'Yes, please.');
    $messages = livewire(Chat::class, ['conversation' => $conversation])->assertSee('Drafting them now.')->instance()->messages();
    expect($messages->pluck('posted')->all())->toBe([true, false, false])
        ->and($messages->last()['regenerable'])->toBeTrue();

    // The turn log lists the posted message as a done turn ended "posted", with no tokens.
    $posted = AgentTurn::query()->forConversation($conversation)->where('finish_reason', 'posted')->sole();
    expect($posted->message_id)->toBe($messageId)->and($posted->provider)->toBeNull()->and($posted->tokensIn())->toBeNull();
    actingAs($this->user(['is_admin' => true]));
    livewire(TurnLog::class)->assertCanSeeTableRecords([$posted])->assertSee('Posted');
});
