<?php

namespace Packstub\Agents\Tests;

use Packstub\Agents\Chat\ChatView;

/**
 * What a test reads off the chat without going through its markup: the
 * engine's AgentChat (messages, live, idle, history …), the view around it
 * (the page URL, what the attach button accepts) and the host (the record
 * search). Stands where the Livewire component's instance stood.
 */
class ChatProbe
{
    public function __construct(protected ChatView $view) {}

    /** @return list<array{ref: string, label: string, resource: string}> */
    public function searchRecords(string $query): array
    {
        return $this->view->host->mentions($query);
    }

    public function canMention(): bool
    {
        return $this->view->host->canMention();
    }

    public function attachmentSettings(): ?array
    {
        return $this->view->attachments();
    }

    public function pageUrl(): string
    {
        return $this->view->pageUrl();
    }

    public function newChatUrl(): string
    {
        return $this->view->newChatUrl();
    }

    public function pollUrl(): ?string
    {
        return $this->view->conversationUrl('turn');
    }

    public function streamUrl(): ?string
    {
        return $this->view->conversationUrl('stream');
    }

    public function __call(string $method, array $arguments): mixed
    {
        return $this->view->chat->refresh()->{$method}(...$arguments);
    }
}
