<?php

use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Packstub\Agents\AgentsPlugin;
use Packstub\Agents\Filament\Pages\Chat;
use Packstub\Agents\Filament\Pages\Chats;
use Packstub\Agents\Filament\Pages\TurnLog;
use Packstub\Agents\Filament\Widgets\TurnsChart;
use Packstub\Agents\Filament\Widgets\TurnStats;
use Packstub\Agents\Models\AgentMessageFeedback;
use Packstub\Agents\Models\AgentTurn;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Tests\Fixtures\Filament\Resources\Widgets\WidgetResource;
use Packstub\Agents\Tests\Fixtures\WidgetAgent;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

it('streams the running turn to the page over the panel\'s event stream route, next to the poll route', function () {
    $user = $this->user();
    actingAs($user);
    WidgetAgent::fake(['Two.']);
    livewire(Chat::class)->call('send', 'How many?');
    $conversation = Conversation::query()->where('participant_id', $user->id)->firstOrFail();

    $page = livewire(Chat::class, ['conversation' => $conversation->id]);
    $stream = Filament::getPanel('admin')->route('packstub-agents.stream', ['conversation' => $conversation->id]);
    $page->assertSee('data-stream="'.$stream.'"', escape: false)
        ->assertSee('data-poll="'.Filament::getPanel('admin')->route('packstub-agents.turn', ['conversation' => $conversation->id]).'"', escape: false);

    config()->set('packstub-agents.chat.stream_seconds', 5);
    $body = get($stream)->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')->streamedContent();
    expect($body)->toContain('event: turn')->toContain('"active":null')->toContain('event: end');

    actingAs($this->user());
    get($stream)->assertNotFound();

    // The client: an EventSource while a turn runs, the poll when it cannot be held open, and the deferred-work rule.
    $script = file_get_contents(__DIR__.'/../../resources/js/agent-chat.js');
    expect($script)
        ->toContain('new EventSource(this.streamUrl')
        ->toContain("source.addEventListener('turn'")
        ->toContain("source.addEventListener('end'")
        ->toContain('self.streamFailures++')
        ->toContain('self.timer = setTimeout(() => self.poll(), delay)')
        ->toContain('self.$wire.$refresh()')
        ->not->toMatch('/setTimeout\(\(\) => this\./');
});

it('renders code blocks with a copy button and colours, a copy button under every answer, and the tools as a timeline', function () {
    $user = $this->user();
    actingAs($user);
    $this->widgets();
    WidgetAgent::fake([new ToolCall('c1', 'list-widgets', ['filters' => ['status' => ['live']], 'limit' => 5]), "Here you go:\n\n```php\n\$live = Widget::live()->count();\n```"]);

    $html = livewire(Chat::class)->call('send', 'Show me the code')->html();

    expect($html)
        ->toContain('<pre><code class="language-php">')
        ->toContain('fi-chat-timeline')
        ->toContain('fi-chat-timeline-name">List Widgets<')
        ->toContain('fi-chat-timeline-args">filters.status: live · limit: 5<')
        ->toContain('fi-chat-timeline-result">2 found<')
        ->toContain('aria-label="'.__('Copy answer').'"')
        ->toContain('aria-pressed="false"')
        ->toContain('role="log" aria-live="polite"')
        ->not->toContain('fi-chat-proposal fi-chat-proposal-'); // a read tool is a timeline row, not a proposal card

    expect(Chat::callSummary(['filters' => ['status' => ['live'], 'query' => 'acme'], 'limit' => 5, 'verbose' => true, 'ignored' => 'x']))->toBe('filters.status: live · filters.query: acme · limit: 5')
        ->and(Chat::callSummary([]))->toBeNull()
        ->and(Chat::resultSummary('{"total":3,"rows":[]}'))->toBe('3 found')
        ->and(Chat::resultSummary('{"rows":[{},{}]}'))->toBe('2 found')
        ->and(Chat::resultSummary('{"total":0}'))->toBe('nothing found')
        ->and(Chat::resultSummary('{"chart":{}}'))->toBe('a chart')
        ->and(Chat::resultSummary('{"table":{}}'))->toBe('a table')
        ->and(Chat::resultSummary('{"error":"nope"}'))->toBe('failed')
        ->and(Chat::resultSummary('{"renamed":true}'))->toBeNull()
        ->and(Chat::resultSummary(null))->toBeNull();

    $script = file_get_contents(__DIR__.'/../../resources/js/agent-chat.js');
    expect($script)->toContain("querySelectorAll('.fi-chat-md pre:not([data-decorated])')")->toContain('navigator.clipboard.writeText');
});

it('renames, pins, exports and continues a chat from the page, and rates with a note', function () {
    $user = $this->user();
    actingAs($user);
    WidgetAgent::fake(['Two widgets are live.']);
    $page = livewire(Chat::class)->call('send', 'How many widgets are live?');
    $conversation = Conversation::query()->where('participant_id', $user->id)->firstOrFail();
    $answer = ConversationMessage::query()->where('conversation_id', $conversation->id)->where('role', 'assistant')->sole();

    $page = livewire(Chat::class, ['conversation' => $conversation->id])
        ->assertSee('aria-label="'.__('Rename').'"', escape: false)
        ->assertSee('aria-pressed="false"', escape: false)
        ->call('rename', 'Live widgets')
        ->assertSee('Live widgets')
        ->call('togglePin');

    expect(AgentChat::for($user, $conversation->id)->pinned())->toBeTrue()
        ->and($conversation->fresh()->title)->toBe('Live widgets');
    livewire(Chat::class, ['conversation' => $conversation->id])->assertSee('title="'.__('Unpin').'"', escape: false)->call('togglePin');
    expect(AgentChat::for($user, $conversation->id)->pinned())->toBeFalse();

    // Export: the transcript as a Markdown download.
    livewire(Chat::class, ['conversation' => $conversation->id])->call('export')->assertFileDownloaded('live-widgets.md');

    // A thumbs-down with a note.
    livewire(Chat::class, ['conversation' => $conversation->id])->call('feedback', $answer->id, 'down', 'Beta is retired');
    $feedback = AgentMessageFeedback::query()->where('message_id', $answer->id)->sole();
    expect($feedback->rating)->toBe('down')->and($feedback->note)->toBe('Beta is retired')->and($feedback->turn_id)->not->toBeNull();
    livewire(Chat::class, ['conversation' => $conversation->id])->assertSee('aria-pressed="true"', escape: false)->assertSee('Beta is retired');

    // Continue: offered only on an answer the length limit cut short; the continuation question stays hidden.
    livewire(Chat::class, ['conversation' => $conversation->id])->assertDontSee(__('Continue'));
    app(AgentConversationStore::class)->markCutShort($conversation->id, 'length');
    WidgetAgent::fake(['and Beta is a draft.']);
    livewire(Chat::class, ['conversation' => $conversation->id])
        ->assertSee(__('Continue'))
        ->call('continueAnswer')
        ->assertSee('and Beta is a draft.')
        ->assertDontSee('Continue exactly where')
        ->assertSee('fi-chat-continued', escape: false);
});

it('pages through the earlier answers of the last question', function () {
    $user = $this->user();
    actingAs($user);
    WidgetAgent::fake(['Two.']);
    livewire(Chat::class)->call('send', 'How many?');
    $conversation = Conversation::query()->where('participant_id', $user->id)->firstOrFail();
    $question = ConversationMessage::query()->where('conversation_id', $conversation->id)->where('role', 'user')->sole();

    livewire(Chat::class, ['conversation' => $conversation->id])->assertDontSee('fi-chat-versions', escape: false);

    WidgetAgent::fake(['Two of them.']);
    $page = livewire(Chat::class, ['conversation' => $conversation->id])->call('regenerate')
        ->assertSee('Two of them.')
        ->assertSee('fi-chat-versions', escape: false)
        ->assertSee('2/2')
        ->assertSee(__('Answer :n', ['n' => 1]));

    $versions = AgentChat::for($user, $conversation->id)->versions($question->id);
    $page->call('showVersion', $question->id, $versions[0]['id'])
        ->assertSee('Two.')
        ->assertNotNotified();
    expect(ConversationMessage::query()->where('conversation_id', $conversation->id)->orderBy('id')->pluck('content')->all())->toBe(['How many?', 'Two.'])
        ->and(AgentChat::for($user, $conversation->id)->versions($question->id)->pluck('text')->all())->toBe(['Two of them.']);

    livewire(Chat::class, ['conversation' => $conversation->id])->call('showVersion', $question->id, 999)->assertNotified();
});

it('sends the files picked in the composer with the question and shows them on the bubble', function () {
    Storage::fake('local');
    config()->set('packstub-agents.chat.attachments.disk', 'local');
    $user = $this->user();
    actingAs($user);

    $page = livewire(Chat::class);
    expect($page->instance()->attachmentSettings())->toMatchArray(['maxFiles' => 5]);
    $page->assertSee('fi-agent-composer-attach', escape: false);

    WidgetAgent::fake(['An invoice for 12 widgets.']);
    $page->set('attachments', [UploadedFile::fake()->image('invoice.png')])
        ->assertSee('invoice.png')
        ->call('send', 'What is this?')
        ->assertSee('An invoice for 12 widgets.')
        ->assertSee('fi-chat-attachment-image', escape: false)
        ->assertSee('alt="invoice.png"', escape: false);

    expect($page->get('attachments'))->toBe([]);
    WidgetAgent::assertPrompted(fn ($prompt) => $prompt->attachments->count() === 1);

    // A file of a type the chat does not accept is refused with a notification, the question kept in the composer.
    livewire(Chat::class)->set('attachments', [UploadedFile::fake()->create('a.zip', 10, 'application/zip')])->call('send', 'Zip?')->assertNotified();

    config()->set('packstub-agents.chat.attachments.enabled', false);
    expect(livewire(Chat::class)->instance()->attachmentSettings())->toBeNull();
    livewire(Chat::class)->assertDontSee('fi-agent-composer-attach', escape: false);
});

it('offers the panel\'s records for "@" and sends the mentioned ones with the question', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha, $beta] = $this->widgets();

    $page = livewire(Chat::class);
    expect($page->instance()->canMention())->toBeTrue()
        ->and($page->instance()->searchRecords('alp'))->toBe([['ref' => "widgets/{$alpha->id}", 'label' => 'Widget Alpha', 'resource' => 'widgets']])
        ->and(collect($page->instance()->searchRecords(''))->pluck('label')->all())->toBe(['Widget Gamma', 'Widget Beta', 'Widget Alpha'])
        ->and($page->instance()->searchRecords('zzz'))->toBe([]);
    $page->assertSee('data-mentions="1"', escape: false)->assertSee(__('Type / for these questions, @ to mention a record.'));

    WidgetAgent::fake(['Alpha is live.']);
    livewire(Chat::class)->call('send', 'Is @Widget Alpha live?', ["widgets/{$alpha->id}"])
        ->assertSee('Is @Widget Alpha live?')
        ->assertSee('fi-chat-mention', escape: false)
        ->assertSee('@ Widget Alpha');
    WidgetAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Records the person mentioned') && str_contains($prompt->prompt, "widgets/{$alpha->id}"));

    $script = file_get_contents(__DIR__.'/../../resources/js/agent-chat.js');
    expect($script)->toContain('self.$wire.searchRecords(query)')->toContain('/(?:^|\\s)@([^\\s@]*)$/')->toContain('packstub-agents:draft:');
});

it('opens the chat as a slide-over from the Ask button and the shortcut, and embedded without the panel chrome', function () {
    $user = $this->user();
    actingAs($user);
    [$alpha] = $this->widgets();

    $plugin = AgentsPlugin::current();
    expect($plugin->hasSlideOver())->toBeTrue()
        ->and($plugin->getShortcut())->toBe('mod+j')
        ->and($plugin->getShortcutLabel())->toBe('Ctrl/⌘+J');

    // A record page: the button dispatches the drawer with the record as context; the drawer sits at the end of the body.
    $html = get(WidgetResource::getUrl('edit', ['record' => $alpha]))->assertOk()->content();
    expect($html)
        ->toContain('$dispatch(&#039;open-agent-drawer&#039;')
        ->toContain('context=widgets%2F'.$alpha->id)
        ->toContain('fi-agent-drawer')
        ->toContain('x-on:open-agent-drawer.window')
        ->toContain('embedded=1');

    // The chat page itself has no drawer, and embedded it has no sidebar.
    $full = get(Chat::getUrl())->assertOk()->content();
    expect($full)->not->toContain('fi-agent-drawer')->toContain('fi-sidebar');
    $embedded = get(Chat::getUrl(['embedded' => 1, 'context' => 'widgets/'.$alpha->id]))->assertOk()->content();
    expect($embedded)->not->toContain('fi-sidebar')->toContain('fi-chat-embedded')->toContain(__('Open full page'))->toContain('About Widget Alpha');

    // Switched off: the button is a plain link and no drawer is rendered.
    $plugin->slideOver(false)->shortcut(null);
    expect($plugin->getShortcutLabel())->toBeNull();
    $html = get(WidgetResource::getUrl('edit', ['record' => $alpha]))->assertOk()->content();
    expect($html)->not->toContain('open-agent-drawer');
    $plugin->slideOver(true)->shortcut('mod+j');
});

it('lists chats pinned first, searches inside the messages, and renames, pins, exports and deletes from the list', function () {
    $user = $this->user();
    actingAs($user);
    WidgetAgent::fake(['Alpha and Beta are the widgets.']);
    livewire(Chat::class)->call('send', 'Which widgets?');
    WidgetAgent::fake(['Nothing urgent.']);
    livewire(Chat::class)->call('send', 'Anything urgent?');
    [$older, $newer] = Conversation::query()->where('participant_id', $user->id)->orderBy('updated_at')->get();

    livewire(Chats::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->searchTable('beta')
        ->assertCanSeeTableRecords([$older])
        ->assertCanNotSeeTableRecords([$newer])
        ->assertSee('Alpha and Beta')
        ->searchTable('')
        ->callTableAction('pin', $older)
        ->assertCanSeeTableRecords([$older, $newer], inOrder: true)
        ->callTableAction('rename', $older, ['title' => 'The widgets'])
        ->callTableAction('export', $older)
        ->assertFileDownloaded('the-widgets.md');

    expect($older->fresh()->title)->toBe('The widgets')
        ->and(AgentChat::pinnedIds($user))->toBe([$older->id]);

    // The sidebar lists the pinned chat first, with its bookmark.
    $html = get(Chat::getUrl())->assertOk()->content();
    expect($html)->toContain('fi-sidebar-chats-pin');
    expect(strpos($html, 'The widgets'))->toBeLessThan(strpos($html, 'Anything urgent?'));

    livewire(Chats::class)->callTableBulkAction('delete', [$older, $newer]);
    expect(Conversation::query()->where('participant_id', $user->id)->count())->toBe(0)
        ->and(AgentChat::pinnedIds($user))->toBe([]);
});

it('shows the week\'s numbers, a chart and the ratings on the AI turns page', function () {
    $user = $this->user(['is_admin' => true]);
    actingAs($user);
    config()->set('packstub-agents.pricing.models', ['test-claude' => ['in' => 3, 'out' => 15]]);
    WidgetAgent::fake(['Two.']);
    livewire(Chat::class)->call('send', 'How many?');
    $conversation = Conversation::query()->where('participant_id', $user->id)->firstOrFail();
    $answer = ConversationMessage::query()->where('conversation_id', $conversation->id)->where('role', 'assistant')->sole();
    livewire(Chat::class, ['conversation' => $conversation->id])->call('feedback', $answer->id, 'down', 'Wrong count');
    $turn = AgentTurn::query()->forConversation($conversation->id)->sole();

    $page = livewire(TurnLog::class)
        ->assertCanSeeTableRecords([$turn])
        ->assertSee(__('Not helpful'))
        ->assertSee('Wrong count')
        ->assertSee(__('Cost'))
        ->filterTable('rating', 'up')
        ->assertCanNotSeeTableRecords([$turn])
        ->filterTable('rating', 'down')
        ->assertCanSeeTableRecords([$turn]);

    expect(TurnLog::ratingOf($turn))->toBe(['rating' => 'down', 'note' => 'Wrong count']);

    livewire(TurnStats::class)->assertSee(__('Turns today'))->assertSee(__('Rated helpful'))->assertSee('0%')->assertSee(__('Cost :cost', ['cost' => '$']), escape: false);
    livewire(TurnsChart::class)->assertSee(__('Turns per day'));
    $data = (new ReflectionMethod(TurnsChart::class, 'getData'))->invoke(new TurnsChart);
    expect($data['labels'])->toHaveCount(14)->and(array_sum($data['datasets'][0]['data']))->toBe(1);

    get(TurnLog::getUrl())->assertOk()->assertSee(__('Turns per day'));
});
