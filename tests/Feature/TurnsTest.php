<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Packstub\Agents\AgentsPlugin;
use Packstub\Agents\Filament\Pages\Chat;
use Packstub\Agents\Jobs\RunAgentTurn;
use Packstub\Agents\Models\AgentMessageFeedback;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentLimits;
use Packstub\Agents\Support\AgentRuntime;
use Packstub\Agents\Support\AgentTurns;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

/** The job the chat pushed for a turn, as a worker would pick it up. */
function pushedJob(string $turnId): RunAgentTurn
{
    return Queue::pushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $turnId)->first();
}

it('runs the turn in a queued job and streams it to the page from the turn row', function () {
    $user = $this->user();
    actingAs($user);
    Queue::fake();

    livewire(Chat::class)->call('send', 'How many widgets are live?');

    $conversation = Conversation::query()->where('participant_id', $user->id)->firstOrFail();
    $turn = AgentTurn::query()->forConversation($conversation->id)->sole();

    // The question is recorded and the job is on the queue with everything a worker needs to be "this request".
    expect($turn->status)->toBe(AgentTurn::PENDING)
        ->and($turn->prompt())->toBe('How many widgets are live?')
        ->and($turn->message_id)->not->toBeNull()
        ->and($turn->input['title'] ?? false)->toBeTrue()
        ->and(ConversationMessage::query()->where('conversation_id', $conversation->id)->pluck('role')->all())->toBe(['user']);

    Queue::assertPushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $turn->id
        && $job->runtime === ['panel' => 'admin', 'tenant' => null, 'user' => $user->id, 'locale' => 'en']
        && $job->tries === 1);

    // Reopened while the job waits: the page attaches to the turn — no Retry, the question is being answered.
    $page = livewire(Chat::class, ['conversation' => $conversation->id])
        ->assertSee('How many widgets are live?')
        ->assertDontSee(__('Retry'))
        ->assertSee('data-active="'.$turn->id.'"', escape: false);

    expect($page->instance()->live()['active']['id'])->toBe($turn->id)
        ->and($page->instance()->messages()->last()['unanswered'])->toBeFalse()
        ->and($page->instance()->messages()->last()['editable'])->toBeFalse();

    // The endpoint the page polls, under the panel's auth.
    $url = Filament::getPanel('admin')->route('packstub-agents.turn', ['conversation' => $conversation->id]);
    $waiting = get($url)->assertOk()
        ->assertJsonPath('active.id', $turn->id)
        ->assertJsonPath('active.status', AgentTurn::PENDING)
        ->assertJsonPath('active.html', '')
        ->json();

    // A worker picks the job up: the answer streams into the row, is stored, the chat is titled.
    WidgetAgent::fake(['Two widgets are live.', 'Live widgets']);
    pushedJob($turn->id)->handle(app(AgentTurns::class));

    $turn->refresh();
    $messages = ConversationMessage::query()->where('conversation_id', $conversation->id)->orderBy('id')->get();

    expect($turn->status)->toBe(AgentTurn::DONE)
        ->and($turn->text)->toContain('Two widgets are live')
        ->and($turn->finished_at)->not->toBeNull()
        ->and($messages->pluck('role')->all())->toBe(['user', 'assistant'])
        ->and($messages[0]->id)->toBe($turn->message_id)
        ->and($messages[1]->content)->toContain('Two widgets are live')
        ->and($conversation->fresh()->title)->toBe('Live widgets');

    $done = get($url)->assertOk()->assertJsonPath('active', null)->json();
    expect($done['version'])->not->toBe($waiting['version']);

    livewire(Chat::class, ['conversation' => $conversation->id])->assertSee('Two widgets are live')->assertSee('data-active=""', escape: false);

    // Another person never sees it.
    actingAs($this->user());
    get($url)->assertNotFound();
});

it('stops a running turn and keeps what it had written, with a marker', function () {
    $user = $this->user();
    actingAs($user);
    Queue::fake();

    $component = livewire(Chat::class)->call('send', 'Tell me about widgets');
    $conversation = Conversation::query()->where('participant_id', $user->id)->firstOrFail();
    $turn = AgentTurn::query()->forConversation($conversation->id)->sole();

    // Stop before the worker even started: nothing is written, the question is kept with a Retry.
    $component->call('stop');
    expect($turn->fresh()->stop_requested_at)->not->toBeNull();

    WidgetAgent::fake(['Widgets have a name, a status and a price.']);
    pushedJob($turn->id)->handle(app(AgentTurns::class));

    expect($turn->fresh()->status)->toBe(AgentTurn::STOPPED)
        ->and(ConversationMessage::query()->where('conversation_id', $conversation->id)->pluck('role')->all())->toBe(['user']);

    $page = livewire(Chat::class, ['conversation' => $conversation->id])
        ->assertSee(__('Stopped before an answer.'))
        ->assertSee(__('Retry'));
    expect($page->instance()->messages()->last()['unanswered'])->toBeTrue();

    // Retry sends the recorded question again; this time the person presses Stop while the first line streams.
    $page->call('retry');
    $retry = AgentTurn::query()->forConversation($conversation->id)->active()->sole();
    expect($retry->message_id)->toBe($turn->message_id);

    app()->instance(AgentTurns::class, new class extends AgentTurns
    {
        public function snapshot(AgentTurn $turn, ?string $text, ?string $statusText): void
        {
            parent::snapshot($turn, $text, $statusText);

            if (str_contains((string) $text, 'First line')) {
                $this->requestStop($turn);
            }
        }
    });

    WidgetAgent::fake(["First line about widgets.\nSecond line that never arrives."]);
    pushedJob($retry->id)->handle(app(AgentTurns::class));

    $messages = ConversationMessage::query()->where('conversation_id', $conversation->id)->orderBy('id')->get();

    expect($retry->fresh()->status)->toBe(AgentTurn::STOPPED)
        ->and($messages->pluck('role')->all())->toBe(['user', 'assistant'])
        ->and($messages[1]->content)->toContain('First line about widgets')
        ->and($messages[1]->content)->not->toContain('never arrives')
        ->and($messages[1]->meta)->toMatchArray(['stopped' => true]);

    $page = livewire(Chat::class, ['conversation' => $conversation->id])
        ->assertSee('First line about widgets')
        ->assertSee(__('(stopped)'))
        ->assertDontSee(__('Retry'));
    expect($page->instance()->messages()->last())->toMatchArray(['stopped' => true, 'regenerable' => true]);
});

it('keeps the follow-ups per conversation, in order, editable until they start', function () {
    $user = $this->user();
    actingAs($user);
    Queue::fake();

    $component = livewire(Chat::class)->call('send', 'First');
    $conversation = Conversation::query()->where('participant_id', $user->id)->firstOrFail();

    $component->call('send', 'Second')->call('send', 'Third')
        ->assertSee('Second')
        ->assertSee('Third')
        ->assertSee(__('Queued'));

    $turns = AgentTurn::query()->forConversation($conversation->id)->orderBy('id')->get();

    // Only the first turn was handed to the queue and recorded; the others wait, unrecorded, so the transcript keeps its order.
    expect($turns->pluck('status')->all())->toBe([AgentTurn::PENDING, AgentTurn::QUEUED, AgentTurn::QUEUED])
        ->and($turns->pluck('message_id')->filter())->toHaveCount(1)
        ->and(ConversationMessage::query()->where('conversation_id', $conversation->id)->count())->toBe(1)
        ->and(array_column($component->instance()->live()['queued'], 'text'))->toBe(['Second', 'Third']);
    Queue::assertPushedTimes(RunAgentTurn::class, 1);

    // A second tab sees the same line; nothing else can start while a turn runs.
    livewire(Chat::class, ['conversation' => $conversation->id])->assertSee('Third')->assertSee(__('Queued'))
        ->call('regenerate')->assertReturned(null)
        ->call('retry')->assertReturned(null);

    // Edit hands the text back and takes the question out of the line; Remove just takes it out.
    $component->call('editQueued', $turns[2]->id)->assertReturned('Third');
    $component->call('removeQueued', $turns[1]->id);
    expect(AgentTurn::query()->forConversation($conversation->id)->open()->count())->toBe(1);

    $component->call('send', 'Fourth');

    // The worker finishes the first turn: the next one starts, and its question is recorded only now.
    WidgetAgent::fake(['One.']);
    pushedJob($turns[0]->id)->handle(app(AgentTurns::class));

    $fourth = AgentTurn::query()->forConversation($conversation->id)->active()->sole();

    expect($turns[0]->fresh()->status)->toBe(AgentTurn::DONE)
        ->and($fourth->prompt())->toBe('Fourth')
        ->and($fourth->message_id)->not->toBeNull()
        ->and(ConversationMessage::query()->where('conversation_id', $conversation->id)->orderBy('id')->pluck('role')->all())->toBe(['user', 'assistant', 'user']);
    Queue::assertPushedTimes(RunAgentTurn::class, 2);
    Queue::assertPushed(RunAgentTurn::class, fn (RunAgentTurn $job) => $job->turnId === $fourth->id);
});

it('shows a turn whose worker went quiet as failed, with a retry', function () {
    $user = $this->user();
    actingAs($user);
    Queue::fake();

    livewire(Chat::class)->call('send', 'Anyone there?');
    $conversation = Conversation::query()->where('participant_id', $user->id)->firstOrFail();
    $turn = AgentTurn::query()->forConversation($conversation->id)->sole();

    AgentTurn::query()->whereKey($turn->id)->update(['updated_at' => now()->subSeconds(AgentTurns::jobTimeout() + 120)]);

    $page = livewire(Chat::class, ['conversation' => $conversation->id])
        ->assertSee(__('The assistant could not answer.'))
        ->assertSee('interrupted')
        ->assertSee(__('Retry'));

    expect($turn->fresh()->status)->toBe(AgentTurn::FAILED)
        ->and($page->instance()->messages()->last()['unanswered'])->toBeTrue();

    get(Filament::getPanel('admin')->route('packstub-agents.turn', ['conversation' => $conversation->id]))->assertOk()->assertJsonPath('active', null);

    // The queue gave up on a job (timeout, lost process): same outcome, and the next waiting question starts.
    $page->call('retry');
    $retry = AgentTurn::query()->forConversation($conversation->id)->active()->sole();
    $page->call('send', 'Still there?');

    pushedJob($retry->id)->failed(new RuntimeException('Job has timed out.'));

    expect($retry->fresh())->toMatchArray(['status' => AgentTurn::FAILED, 'error' => 'Job has timed out.'])
        ->and(AgentTurn::query()->forConversation($conversation->id)->active()->sole()->prompt())->toBe('Still there?');
});

it('regenerates the last answer and resends an edited question', function () {
    $user = $this->user();
    actingAs($user);

    WidgetAgent::fake(['Two widgets are live.']);
    $component = livewire(Chat::class)->call('send', 'How many?');

    $conversation = Conversation::query()->where('participant_id', $user->id)->firstOrFail();
    $messages = ConversationMessage::query()->where('conversation_id', $conversation->id)->orderBy('id')->get();
    $component->call('feedback', $messages[1]->id, 'up');

    $component = livewire(Chat::class, ['conversation' => $conversation->id])
        ->assertSee(__('Regenerate'))
        ->assertSee(__('Edit and send again'));
    expect($component->instance()->messages()->first()['editable'])->toBeTrue()
        ->and($component->instance()->messages()->last()['regenerable'])->toBeTrue();

    // Regenerate: the answer (and its feedback) go, the same recorded question is answered again.
    WidgetAgent::fake(['Two are live: Alpha and Gamma.']);
    $component->call('regenerate');

    $after = ConversationMessage::query()->where('conversation_id', $conversation->id)->orderBy('id')->get();
    expect($after->pluck('role')->all())->toBe(['user', 'assistant'])
        ->and($after[0]->id)->toBe($messages[0]->id)
        ->and($after[1]->content)->toContain('Alpha and Gamma')
        ->and(AgentMessageFeedback::query()->where('message_id', $messages[1]->id)->exists())->toBeFalse();

    // Edit and send again: the recorded question is rewritten in place and answered again.
    WidgetAgent::fake(['Three: draft, live and retired.']);
    $component->call('resend', 'Which statuses exist?');

    $edited = ConversationMessage::query()->where('conversation_id', $conversation->id)->orderBy('id')->get();
    expect($edited->pluck('role')->all())->toBe(['user', 'assistant'])
        ->and($edited[0]->id)->toBe($messages[0]->id)
        ->and($edited[0]->content)->toBe('Which statuses exist?')
        ->and($edited[1]->content)->toContain('Three');
    WidgetAgent::assertPrompted(fn ($prompt) => $prompt->prompt === 'Which statuses exist?');

    // An edit the budget refuses is kept as the question, with the reason under it and a Retry — nothing typed is lost.
    config(['packstub-agents.limits.prompt_max_chars' => 5]);
    AgentLimits::flush();
    $component->call('resend', 'far too long')->assertNotified();
    expect(ConversationMessage::query()->where('conversation_id', $conversation->id)->orderBy('id')->pluck('content')->all())
        ->toBe(['far too long']);
    livewire(Chat::class, ['conversation' => $conversation->id])->assertSee('too long')->assertSee(__('Retry'));
});

it('puts a worker into the shape of the panel request and cleans up after', function () {
    $user = $this->user(['locale' => 'de']);
    actingAs($user);

    expect(AgentRuntime::capture())->toBe(['panel' => 'admin', 'tenant' => null, 'user' => $user->id, 'locale' => 'en', 'guard' => 'web']);

    auth()->logout();
    expect(auth()->user())->toBeNull();

    $leave = AgentRuntime::enter(['panel' => 'admin', 'tenant' => null, 'user' => $user->id, 'locale' => 'de']);

    expect(auth()->user()?->is($user))->toBeTrue()
        ->and(Filament::auth()->user()?->is($user))->toBeTrue()
        ->and(Filament::getCurrentPanel()?->getId())->toBe('admin')
        ->and(app()->getLocale())->toBe('de');

    $leave();

    expect(auth()->user())->toBeNull()
        ->and(app()->getLocale())->toBe('en');
});

it('marks an answer the provider ended early so it can be produced again', function () {
    $user = $this->user();
    actingAs($user);
    Queue::fake();

    $end = fn (FinishReason $reason) => new StreamEnd('e', $reason->value, new Usage, time());

    // A stream without its end event was dropped; the model's limit and the provider's filter end an answer early too.
    expect(AgentTurns::cutShortReason(null))->toBe('dropped')
        ->and(AgentTurns::cutShortReason($end(FinishReason::Length)))->toBe('length')
        ->and(AgentTurns::cutShortReason($end(FinishReason::ContentFilter)))->toBe('content_filter')
        ->and(AgentTurns::cutShortReason($end(FinishReason::Error)))->toBe('error')
        ->and(AgentTurns::cutShortReason($end(FinishReason::Stop)))->toBeNull()
        ->and(AgentTurns::cutShortReason($end(FinishReason::ToolCalls)))->toBeNull();

    livewire(Chat::class)->call('send', 'How many widgets are live?');
    $conversation = Conversation::query()->where('participant_id', $user->id)->firstOrFail();
    $turn = AgentTurn::query()->forConversation($conversation->id)->sole();

    WidgetAgent::fake(['Two widgets are']);
    pushedJob($turn->id)->handle(app(AgentTurns::class));

    // The faked stream ends properly: nothing is marked.
    $answer = ConversationMessage::query()->where('conversation_id', $conversation->id)->where('role', 'assistant')->sole();
    expect($turn->fresh()->status)->toBe(AgentTurn::DONE)
        ->and(AgentConversationStore::cutShort($answer->meta))->toBeNull();

    // The provider's length limit ended it: the stored answer is marked, the page says so and offers Regenerate.
    app(AgentConversationStore::class)->markCutShort($conversation->id, 'length');

    expect(AgentConversationStore::cutShort($answer->fresh()->meta))->toBe('length')
        ->and(Chat::cutShortText('length'))->toBe(__('The answer hit the model\'s length limit.'))
        ->and(Chat::cutShortText('dropped'))->toBe(__('The provider closed the stream before the answer was complete.'));

    $page = livewire(Chat::class, ['conversation' => $conversation->id])
        ->assertSee('Two widgets are')
        ->assertSee(__('(cut short)'))
        ->assertSee(__('The answer hit the model\'s length limit.'))
        ->assertDontSee(__('Retry'));
    expect($page->instance()->messages()->last())->toMatchArray(['cutShort' => 'length', 'stopped' => false, 'regenerable' => true]);
});

it('runs the turn inside the request on the sync driver, whatever the queue is', function () {
    $user = $this->user();
    actingAs($user);

    // The app's queue would drop the job on the floor; the sync driver never hands it there.
    config()->set('queue.connections.dropped', ['driver' => 'null']);
    config()->set('queue.default', 'dropped');
    config()->set('packstub-agents.chat.driver', 'sync');
    WidgetAgent::fake(['Two widgets are live.', 'Live widgets']);

    livewire(Chat::class)->call('send', 'How many widgets are live?');

    $conversation = Conversation::query()->where('participant_id', $user->id)->firstOrFail();
    $turn = AgentTurn::query()->forConversation($conversation->id)->sole();

    // The job ran inside the request and the answer is already stored.
    expect($turn->status)->toBe(AgentTurn::DONE)
        ->and(ConversationMessage::query()->where('conversation_id', $conversation->id)->pluck('role')->all())->toBe(['user', 'assistant']);

    livewire(Chat::class, ['conversation' => $conversation->id])->assertSee('Two widgets are live');
});

it('refuses a turn driver it does not know', function () {
    actingAs($this->user());
    config()->set('packstub-agents.chat.driver', 'thread');

    livewire(Chat::class)->call('send', 'How many widgets are live?');
})->throws(InvalidArgumentException::class, 'Unknown agent turn driver [thread]');

it('takes the turn driver from the plugin', function () {
    $plugin = AgentsPlugin::make()->chat(driver: 'sync');
    $plugin->register(Filament::getPanel('admin'));

    expect(config('packstub-agents.chat.driver'))->toBe('sync');
});
