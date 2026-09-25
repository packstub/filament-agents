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
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Models\AgentPinnedConversation;
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
        return [static::getRouteName(), Chat::getRouteName()];
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

        return $table
            ->query(fn () => $own()->orderByRaw('case when id in ('.$pinned->toRawSql().') then 0 else 1 end'))
            ->defaultSort('updated_at', 'desc')
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
