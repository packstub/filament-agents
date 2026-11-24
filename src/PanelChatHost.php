<?php

namespace Packstub\FilamentAgents;

use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentView;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Packstub\Agents\Chat\ApprovalForms;
use Packstub\Agents\Chat\Hosts\RouteHost;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentResources;
use Packstub\FilamentAgents\Contracts\HasApprovalForm;
use Packstub\FilamentAgents\Pages\Chat;
use Packstub\FilamentAgents\Pages\Chats;

/**
 * The chat inside the panel: its page is the Chat page, its endpoints are
 * the panel's own (authenticated, tenant) routes, its buttons, dropdowns and
 * icons are Filament's, "@" searches the panel's resources, and a table or a
 * chart under an answer is Filament's. Outside the assistant's panel — a page
 * of the app's own that renders <x-agents-chat /> — it steps aside for the
 * plain host.
 */
class PanelChatHost extends RouteHost implements ApprovalForms
{
    protected function inPanel(): bool
    {
        return app(FilamentContext::class)->inPanel();
    }

    public function chatUrl(?string $conversation = null, array $query = []): string
    {
        if (! $this->inPanel()) {
            return parent::chatUrl($conversation, $query);
        }

        return Chat::getUrl(array_filter(['conversation' => $conversation, ...$query], fn ($value) => $value !== null && $value !== ''));
    }

    public function chatsUrl(): ?string
    {
        return $this->inPanel() ? Chats::getUrl() : parent::chatsUrl();
    }

    public function endpoint(string $name, array $parameters = []): string
    {
        if (! $this->inPanel()) {
            return parent::endpoint($name, $parameters);
        }

        return Filament::getCurrentPanel()->route('packstub-agents.'.$name, array_filter([...$parameters, 'tenant' => Filament::getTenant()]));
    }

    /** "@" offers records when the panel has resources the assistant knows. */
    public function canMention(): bool
    {
        return $this->inPanel() ? Agents::resourceClasses() !== [] : parent::canMention();
    }

    /**
     * Every agent resource of the panel searched on its globally searchable attributes (or its title attribute), a
     * few per resource, as ref ("orders/12"), label and resource name. A record the resource's canView() denies is
     * left out, so the picker never shows a label of a record the person may not open.
     */
    public function mentions(string $query): array
    {
        if (! $this->inPanel()) {
            return parent::mentions($query);
        }

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
            $limit = $query === '' ? 3 : 5;
            $records = $resource::getEloquentQuery()
                ->when($query !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($attributes, $query) {
                    foreach ($attributes as $attribute) {
                        $q->orWhereLike($attribute, '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%', caseSensitive: false);
                    }
                }))
                ->orderByDesc((new $model)->getKeyName())
                ->limit($limit * 2) // a few more than the page, since canView() may drop some
                ->get()
                ->filter(fn (Model $record) => $resource::canView($record))
                ->take($limit);

            foreach ($records as $record) {
                $results[] = ['ref' => $key.'/'.$record->getKey(), 'label' => $resource::agentContextLabel($record), 'resource' => (string) $resource::getPluralModelLabel()];
            }
        }

        return array_slice($results, 0, 12);
    }

    public function kit(): string
    {
        return $this->inPanel() ? 'filament' : parent::kit();
    }

    public function embedView(string $kind): ?string
    {
        if (! $this->inPanel()) {
            // Outside the panel the resource's Filament table cannot run: the same rows, read-only.
            return $kind === 'table' ? 'packstub-agents::embeds.plain-table' : parent::embedView($kind);
        }

        return match ($kind) {
            'table' => 'packstub-agents::embeds.table',
            'chart' => 'packstub-agents::embeds.chart',
            default => null,
        };
    }

    /** The chat's script as an Alpine component Filament loads on demand, and the chat's render hooks. */
    /** A waiting proposal of a tool with HasApprovalForm gets its form on the card, in the panel only. */
    public function approvalView(array $tool): ?string
    {
        if (! $this->inPanel() || ! AgentChat::writeTools()->get((string) ($tool['tool'] ?? '')) instanceof HasApprovalForm) {
            return null;
        }

        return 'packstub-agents::embeds.approval-form';
    }

    public function slot(string $name): string
    {
        if (! $this->inPanel()) {
            return parent::slot($name);
        }

        return match ($name) {
            'script' => FilamentAsset::getAlpineComponentSrc('agent-chat', 'packstub/filament-agents'),
            'composer.tools' => (string) FilamentView::renderHook(ChatRenderHook::COMPOSER_TOOLS_BEFORE, scopes: [Chat::class])->toHtml(),
            'composer.after' => (string) FilamentView::renderHook(ChatRenderHook::COMPOSER_AFTER, scopes: [Chat::class])->toHtml(),
            default => '',
        };
    }

    /** Notices are Filament notifications (the chat page listens for `agents-notify`). */
    public function notifies(): bool
    {
        return $this->inPanel() ? true : parent::notifies();
    }

    /** The slide-over's head has the button: it opens the chat that is on screen. */
    public function linksFullPage(): bool
    {
        return $this->inPanel() ? true : parent::linksFullPage();
    }
}
