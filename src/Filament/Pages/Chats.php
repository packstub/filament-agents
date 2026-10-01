<?php

namespace Packstub\Agents\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Models\AgentPinnedConversation;
use Packstub\Agents\Models\ConversationClassification;
use Packstub\Agents\Support\AgentChat;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\AgentModels;

/**
 * Every conversation the current person had with the assistant in this
 * workspace: pinned ones first, the search box looking into the messages
 * as well as the titles, each chat renamed, pinned, exported or deleted
 * from its row.
 */
class Chats extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $slug = 'chats';

    protected string $view = 'packstub-agents::pages.chats';

    public static function canAccess(): bool
    {
        return AgentModels::enabled();
    }

    /**
     * A panel with a sidebar lists the recent chats there (the sidebar hook) and needs no item for this page. A panel
     * with top navigation has no such place, so it gets an "Ask …" item — the assistant's name and icon — that leads
     * here and stays active on a chat; the topbar button keeps opening a new chat with the record being viewed.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && (Filament::getCurrentOrDefaultPanel()?->hasTopNavigation() ?? false);
    }

    public static function getNavigationLabel(): string
    {
        return Agents::name();
    }

    /** @return string|array<string> */
    public static function getNavigationItemActiveRoutePattern(): string|array
    {
        return [static::getRouteName(), Chat::getRouteName(), ResourceTable::getRouteName()];
    }

    public function getTitle(): string|Htmlable
    {
        return __('Chats');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new')->label(__('New chat'))->icon(Heroicon::OutlinedPlus)->url(Chat::getUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        $own = fn () => Conversation::query()
            ->where('participant_type', auth()->user()?->getMorphClass())
            ->where('participant_id', auth()->id());
        $pinned = AgentPinnedConversation::query()->select('conversation_id');

        // With classification on (config `classify`), each chat carries what it is about, how the person sounded and
        // whether it was resolved: three columns to sort by and three filters, read from the classifier's table.
        $classified = self::classified();
        $conversations = (new Conversation)->getTable();
        $classification = fn (string $column) => ConversationClassification::query()->select($column)->whereColumn('conversation_id', $conversations.'.id')->limit(1);
        $matching = fn (string $column, mixed $value) => ConversationClassification::query()->select('conversation_id')->where($column, $value);

        return $table
            ->query(fn () => $own()
                ->when($classified, fn (Builder $query) => $query->addSelect(['topic' => $classification('topic'), 'sentiment' => $classification('sentiment'), 'resolved' => $classification('resolved')]))
                ->orderByRaw('case when id in ('.$pinned->toRawSql().') then 0 else 1 end'))
            ->defaultSort('updated_at', 'desc')
            ->filters($classified ? [
                SelectFilter::make('topic')->label(__('Topic'))
                    ->options(fn () => ConversationClassification::query()->whereIn('conversation_id', $own()->select('id'))->distinct()->orderBy('topic')->pluck('topic', 'topic')->map(fn (string $topic) => Str::headline($topic))->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->whereIn('id', $matching('topic', $data['value'])) : $query),
                SelectFilter::make('sentiment')->label(__('Sentiment'))
                    ->options(self::sentiments())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->whereIn('id', $matching('sentiment', $data['value'])) : $query),
                TernaryFilter::make('resolved')->label(__('Resolved'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereIn('id', $matching('resolved', true)),
                        false: fn (Builder $query) => $query->whereIn('id', $matching('resolved', false)),
                        blank: fn (Builder $query) => $query,
                    ),
            ] : [])
            ->columns([
                IconColumn::make('pinned')->label('')
                    ->state(fn (Conversation $c) => AgentPinnedConversation::query()->where('conversation_id', $c->id)->exists())
                    ->icon(fn (bool $state) => $state ? Heroicon::Bookmark : null)
                    ->color('primary')
                    ->width('2rem'),
                TextColumn::make('title')->label(__('Chat'))
                    // The search box looks into the messages as well as the titles.
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $q) => $q
                        ->where('title', 'like', '%'.self::escapeLike($search).'%')
                        ->orWhereIn('id', ConversationMessage::query()->select('conversation_id')->where('content', 'like', '%'.self::escapeLike($search).'%'))))
                    ->description(fn (Conversation $c) => $this->snippetFor($c))
                    ->url(fn (Conversation $c) => Chat::getUrl(['conversation' => $c->id])),
                ...($classified ? [
                    TextColumn::make('topic')->label(__('Topic'))
                        ->badge()->color('gray')
                        ->formatStateUsing(fn (?string $state) => $state === null ? null : Str::headline($state))
                        ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('topic', $direction)),
                    TextColumn::make('sentiment')->label(__('Sentiment'))
                        ->badge()
                        ->formatStateUsing(fn (?string $state) => self::sentiments()[$state] ?? $state)
                        ->color(fn (?string $state) => match ($state) {
                            'positive' => 'success',
                            'negative' => 'danger',
                            default => 'gray',
                        })
                        ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('sentiment', $direction)),
                    IconColumn::make('resolved')->label(__('Resolved'))
                        ->state(fn (Conversation $c) => $c->getAttribute('resolved') === null ? null : (bool) $c->getAttribute('resolved'))
                        ->boolean()
                        ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('resolved', $direction)),
                ] : []),
                TextColumn::make('updated_at')->label(__('Last message'))->since()->sortable(),
            ])
            ->recordUrl(fn (Conversation $c) => Chat::getUrl(['conversation' => $c->id]))
            ->recordActions([
                Action::make('pin')
                    ->label(fn (Conversation $c) => AgentPinnedConversation::query()->where('conversation_id', $c->id)->exists() ? __('Unpin') : __('Pin'))
                    ->icon(fn (Conversation $c) => AgentPinnedConversation::query()->where('conversation_id', $c->id)->exists() ? Heroicon::Bookmark : Heroicon::OutlinedBookmark)
                    ->action(function (Conversation $record): void {
                        $chat = AgentChat::for(auth()->user(), $record->id);
                        $chat->pinned() ? $chat->unpin() : $chat->pin();
                    }),
                Action::make('rename')
                    ->label(__('Rename'))
                    ->icon(Heroicon::OutlinedPencil)
                    ->schema([TextInput::make('title')->label(__('Title'))->required()->maxLength(100)])
                    ->fillForm(fn (Conversation $record) => ['title' => $record->title])
                    ->action(fn (Conversation $record, array $data) => AgentChat::for(auth()->user(), $record->id)->rename((string) $data['title'])),
                Action::make('export')
                    ->label(__('Export'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(function (Conversation $record) {
                        $transcript = AgentChat::for(auth()->user(), $record->id)->transcript();
                        $name = Str::slug(Str::limit((string) $record->title, 60, ''), '-') ?: 'chat';

                        return response()->streamDownload(fn () => print $transcript, "{$name}.md", ['Content-Type' => 'text/markdown; charset=UTF-8']);
                    }),
                DeleteAction::make()->label(__('Delete'))->action(fn (Conversation $record) => app(AgentConversationStore::class)->deleteConversation($record->id)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->action(function (Collection $records): void {
                        $store = app(AgentConversationStore::class);
                        $records->each(fn (Conversation $c) => $store->deleteConversation($c->id));
                    }),
                ]),
            ])
            ->emptyStateHeading(__('No chats yet'))
            ->emptyStateDescription(__('Ask anything about your workspace.'));
    }

    /** Whether chats are classified (config `classify.enabled`), so the list shows and filters by topic, sentiment and resolved. */
    public static function classified(): bool
    {
        return (bool) config('packstub-agents.classify.enabled', false);
    }

    /** @return array<string, string> */
    protected static function sentiments(): array
    {
        return ['positive' => __('Positive'), 'neutral' => __('Neutral'), 'negative' => __('Negative')];
    }

    /** A line of the first message that matches the search, under the title; nothing without a search. */
    protected function snippetFor(Conversation $conversation): ?string
    {
        $search = trim((string) $this->getTableSearch());

        if ($search === '') {
            return null;
        }

        $content = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('content', 'like', '%'.self::escapeLike($search).'%')
            ->orderBy('id')
            ->value('content');

        return $content === null ? null : AgentChat::snippet((string) $content, $search);
    }

    protected static function escapeLike(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
    }
}
