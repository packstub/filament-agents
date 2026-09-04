<?php

namespace Packstub\Agents\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Laravel\Ai\Models\Conversation;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Support\AgentModels;

/** Every conversation the current person had with the assistant in this workspace. */
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
     * A panel with a sidebar lists the recent chats there (the sidebar hook), so the page needs no item of its own;
     * a panel with top navigation has no such place, so it gets an "Ask …" item that stays active on a chat.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && (Filament::getCurrentOrDefaultPanel()?->hasTopNavigation() ?? false);
    }

    public static function getNavigationLabel(): string
    {
        return Agents::name();
    }

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
        return $table
            ->query(Conversation::query()
                ->where('participant_type', auth()->user()?->getMorphClass())
                ->where('participant_id', auth()->id()))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title')->label(__('Chat'))->searchable()->url(fn (Conversation $c) => Chat::getUrl(['conversation' => $c->id])),
                TextColumn::make('updated_at')->label(__('Last message'))->since()->sortable(),
            ])
            ->recordUrl(fn (Conversation $c) => Chat::getUrl(['conversation' => $c->id]))
            ->recordActions([
                DeleteAction::make()->label(__('Delete'))->action(function (Conversation $record) {
                    $record->messages()->delete();
                    $record->delete();
                }),
            ])
            ->emptyStateHeading(__('No chats yet'))
            ->emptyStateDescription(__('Ask anything about your workspace.'));
    }
}
