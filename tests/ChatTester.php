<?php

namespace Packstub\FilamentAgents\Tests;

use BadMethodCallException;
use Filament\Facades\Filament;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Packstub\Agents\Chat\ChatHost;
use Packstub\Agents\Chat\ChatView;
use Packstub\FilamentAgents\Pages\Chat;
use PHPUnit\Framework\Assert;

/**
 * Drives the panel's chat the way the browser does: it opens the Chat page
 * (a GET), then every action is a POST to the panel's chat endpoints, and
 * what it "sees" is the chat as the last answer rendered it. Shaped like
 * Livewire's test helper, which these tests used while the chat was a
 * Livewire component: chat(['conversation' => $id])->call('send', 'Hi')->assertSee('Hello').
 */
class ChatTester
{
    /** The page as it was opened. */
    public TestResponse $page;

    /** The last action's answer. */
    public ?TestResponse $last = null;

    public ?string $conversation;

    protected mixed $returned = null;

    /** @var array<string, mixed> what the page holds between actions: the composer's text, the model, the picked files */
    protected array $state = ['prompt' => null, 'model' => null, 'attachments' => []];

    /** The chat method names the tests call (the Livewire actions of old), and the action each one is now. */
    protected const array ACTIONS = [
        'send' => 'send', 'resend' => 'resend', 'regenerate' => 'regenerate', 'continueAnswer' => 'continue', 'retry' => 'retry',
        'decide' => 'decide', 'stop' => 'stop', 'removeQueued' => 'queued-remove', 'editQueued' => 'queued-edit', 'feedback' => 'feedback',
        'showVersion' => 'version', 'rename' => 'rename', 'togglePin' => 'pin', 'compressNow' => 'compress', 'continueInNewChat' => 'continue-in-new',
    ];

    /**
     * @param  array<string, mixed>  $parameters  the page's route parameters (conversation)
     * @param  array<string, mixed>  $query  its query string (context, embedded, prompt)
     */
    public function __construct(protected TestCase $test, array $parameters = [], protected array $query = [])
    {
        $this->conversation = $parameters['conversation'] ?? null;
        $this->page = $test->get(Chat::getUrl([...$parameters, ...$query]));
        // A request through the panel's routes leaves the panel current, as the page's own actions would find it.
        Filament::setCurrentPanel(Filament::getCurrentPanel() ?? Filament::getDefaultPanel());
    }

    public function set(string $name, mixed $value): static
    {
        $this->state[$name] = $value;

        return $this;
    }

    public function get(string $name): mixed
    {
        return match ($name) {
            'conversation' => $this->conversation,
            'attachments' => $this->state['attachments'],
            'autoSend' => $this->data('auto-send') === '1',
            'embedded' => $this->data('embedded') === '1',
            'context' => $this->data('context') ?: null,
            'prompt', 'model' => $this->data($name),
            default => $this->state[$name] ?? null,
        };
    }

    /** A value of the chat's state element (data-…), as the script reads it. */
    public function data(string $name): ?string
    {
        return preg_match('/x-ref="state"[^>]*\sdata-'.preg_quote($name, '/').'="([^"]*)"/s', $this->html(), $found) ? html_entity_decode($found[1], ENT_QUOTES) : null;
    }

    public function call(string $method, mixed ...$arguments): static
    {
        if ($method === 'export') {
            $this->last = $this->test->get($this->host()->endpoint('export', ['conversation' => $this->conversation]));

            return $this;
        }

        $action = self::ACTIONS[$method] ?? throw new BadMethodCallException("The chat has no [{$method}] action.");
        $files = [];

        $data = match ($method) {
            'send' => ['prompt' => $arguments[0] ?? $this->state['prompt'] ?? $this->data('prompt'), 'mentions' => $arguments[1] ?? []],
            'resend' => ['prompt' => $arguments[0]],
            'retry' => ['message' => $arguments[0] ?? null],
            'decide' => ['call' => $arguments[0], 'approve' => $arguments[1]],
            'removeQueued', 'editQueued' => ['turn' => $arguments[0]],
            'feedback' => ['message' => $arguments[0], 'rating' => $arguments[1], 'note' => $arguments[2] ?? null],
            'showVersion' => ['question' => $arguments[0], 'version' => $arguments[1]],
            'rename' => ['title' => $arguments[0]],
            default => [],
        };

        if ($method === 'send') {
            $files = array_values(array_filter($this->state['attachments'], fn ($file) => $file instanceof UploadedFile));
            $this->state['attachments'] = [];
            $this->state['prompt'] = null;
        }

        $body = array_filter([
            'conversation' => $this->conversation,
            'model' => $this->state['model'],
            'context' => $this->query['context'] ?? null,
            'embedded' => $this->query['embedded'] ?? null,
            ...$data,
        ], fn ($value) => $value !== null);

        $url = $this->host()->endpoint('act', ['action' => $action]);

        $this->last = $files === []
            ? $this->test->postJson($url, $body)
            : $this->test->post($url, [...array_map(fn ($value) => is_array($value) ? json_encode($value) : $value, $body), 'files' => $files], ['Accept' => 'application/json']);

        $this->conversation = $this->last->json('turn.conversation') ?? $this->conversation;
        $this->returned = $method === 'editQueued' ? $this->last->json('text') : $this->last->json('turn');

        return $this;
    }

    /** The chat as the page shows it now: the last action's render, or the page as it was opened. */
    public function html(): string
    {
        return (string) ($this->last?->json('html') ?? $this->page->getContent());
    }

    /** What the page would read next from the engine, for the assertions on the chat itself. */
    public function instance(): ChatProbe
    {
        return new ChatProbe($this->view());
    }

    public function view(): ChatView
    {
        return ChatView::make($this->conversation, $this->state['model'], $this->query['context'] ?? null, (bool) ($this->query['embedded'] ?? false));
    }

    protected function host(): ChatHost
    {
        return app(ChatHost::class);
    }

    protected function shown(): TestResponse
    {
        return new TestResponse(new Response($this->html()));
    }

    public function assertSee(mixed $value, bool $escape = true): static
    {
        $this->shown()->assertSee($value, $escape);

        return $this;
    }

    public function assertDontSee(mixed $value, bool $escape = true): static
    {
        $this->shown()->assertDontSee($value, $escape);

        return $this;
    }

    public function assertSeeHtml(mixed $value): static
    {
        return $this->assertSee($value, false);
    }

    public function assertDontSeeHtml(mixed $value): static
    {
        return $this->assertDontSee($value, false);
    }

    public function assertSeeInOrder(array $values, bool $escape = true): static
    {
        $this->shown()->assertSeeInOrder($values, $escape);

        return $this;
    }

    public function assertSet(string $name, mixed $value): static
    {
        Assert::assertSame($value, $this->get($name), "The chat's [{$name}] is not what was expected.");

        return $this;
    }

    public function assertReturned(mixed $value): static
    {
        Assert::assertEquals($value, $this->returned);

        return $this;
    }

    /** @return list<array{level: string, title: string, body: ?string, persistent: bool}> */
    public function notices(): array
    {
        return $this->last?->json('notices') ?? [];
    }

    /** The last action told the person something (with this title, when one is given). */
    public function assertNotified(?string $title = null): static
    {
        $titles = array_column($this->notices(), 'title');

        $title === null
            ? Assert::assertNotEmpty($titles, 'The action left no notice.')
            : Assert::assertContains($title, $titles, 'No notice with that title: '.json_encode($titles));

        return $this;
    }

    public function assertNotNotified(): static
    {
        Assert::assertSame([], $this->notices());

        return $this;
    }

    public function assertRedirect(?string $url = null): static
    {
        $redirect = $this->last?->json('redirect');

        Assert::assertNotNull($redirect, 'The action did not send the page anywhere.');

        if ($url !== null) {
            Assert::assertSame($url, $redirect);
        }

        return $this;
    }

    public function assertNoRedirect(): static
    {
        Assert::assertNull($this->last?->json('redirect'));

        return $this;
    }

    public function assertFileDownloaded(string $name): static
    {
        Assert::assertStringContainsString($name, (string) $this->last?->headers->get('Content-Disposition'));

        return $this;
    }

    public function assertOk(): static
    {
        ($this->last ?? $this->page)->assertOk();

        return $this;
    }

    public function assertNotFound(): static
    {
        ($this->last ?? $this->page)->assertNotFound();

        return $this;
    }

    public function assertForbidden(): static
    {
        ($this->last ?? $this->page)->assertForbidden();

        return $this;
    }
}
