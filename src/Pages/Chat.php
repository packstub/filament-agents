<?php

namespace Packstub\FilamentAgents\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;
use Packstub\Agents\Chat\ChatPresenter;
use Packstub\Agents\Chat\ChatView;
use Packstub\Agents\Support\AgentModels;

/**
 * One conversation with the assistant, in the panel. The chat itself is the
 * engine's (Packstub\Agents\Chat): views, an Alpine component and HTTP
 * endpoints the plugin registers on the panel's authenticated routes, with
 * no Livewire in between. This page is what only the panel knows: the URL,
 * the title, the layout. It renders the chat once and never again (the chat
 * keeps itself up to date), so nothing here is a Livewire action. With
 * ?embedded=1 the page renders without the panel's chrome, for the
 * slide-over the "Ask …" button opens over any page.
 */
class Chat extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'chat/{conversation?}';

    protected string $view = 'packstub-agents::pages.chat';

    #[Locked]
    public ?string $conversation = null;

    #[Locked]
    public ?string $context = null;

    /** The page rendered without the panel's chrome, inside the slide-over. */
    #[Locked]
    public bool $embedded = false;

    /** The chat as it was opened, for the first render. */
    protected ?ChatView $chatView = null;

    public static function canAccess(): bool
    {
        return AgentModels::enabled();
    }

    /**
     * Another person's conversation is not found; a first question the app held back starts now; a question the
     * app left in the session is sent on load, one from the URL (?prompt=) waits in the composer.
     */
    public function mount(?string $conversation = null): void
    {
        $this->conversation = $conversation;
        $this->context = is_string($context = request()->query('context')) ? $context : null;
        $this->embedded = (bool) request()->query('embedded');

        $this->chatView = ChatView::open($conversation, $this->context, $this->embedded, is_string($prompt = request()->query('prompt')) ? $prompt : null);
    }

    /** Inside the slide-over the page has no sidebar, topbar or breadcrumbs. */
    public function getLayout(): string
    {
        return $this->embedded ? 'filament-panels::components.layout.base' : parent::getLayout();
    }

    /** The chat for this page. After the first render (a Livewire request of the page's own) it is read again from what the page was opened with. */
    protected function chatView(): ChatView
    {
        return $this->chatView ??= ChatView::make($this->conversation, null, $this->context, $this->embedded);
    }

    /** The engine's chat, rendered: see resources/views/pages/chat.blade.php. */
    public function chatHtml(): string
    {
        return $this->chatView()->render();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->chatView()->title();
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    /** @deprecated 1.20 the chat's views call ChatPresenter; kept for views published before then. */
    public static function previewValue(mixed $value): string
    {
        return ChatPresenter::previewValue($value);
    }

    /**
     * @deprecated 1.20 see previewValue()
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function callSummary(array $arguments): ?string
    {
        return ChatPresenter::callSummary($arguments);
    }

    /** @deprecated 1.20 see previewValue() */
    public static function resultSummary(mixed $result): ?string
    {
        return ChatPresenter::resultSummary($result);
    }
}
