<?php

namespace Packstub\Agents\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Packstub\Agents\Facades\Agents;

/**
 * Agent access: mint a token so Claude Code, Claude Desktop or any MCP
 * client can work inside this workspace as you (your role, this tenant).
 * The token is shown once; it carries the workspace as a "tenant:{slug}"
 * ability so AuthenticateAgent can refuse it on any other workspace.
 */
class AgentAccess extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'agent-access';

    protected string $view = 'packstub-agents::pages.agent-access';

    public ?string $plainTextToken = null;

    public static function canAccess(): bool
    {
        return (bool) config('packstub-agents.mcp.enabled', true) && Agents::allows(Agents::agentAccessAbility());
    }

    public static function getNavigationGroup(): ?string
    {
        return Agents::agentAccessGroup();
    }

    public static function getNavigationLabel(): string
    {
        return __('Agent access');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Agent access');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Let Claude Code, Claude Desktop or any MCP client work in this workspace with your role.');
    }

    public function mcpUrl(): string
    {
        $path = trim((string) config('packstub-agents.mcp.path', 'mcp'), '/');

        return url('/'.str_replace('{tenant}', $this->slug() ?? '', $path));
    }

    /** The workspace slug as it appears in the MCP path, or null in a panel without tenancy. */
    public function slug(): ?string
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            return null;
        }

        $attribute = Filament::getCurrentPanel()?->getTenantSlugAttribute();

        return (string) ($attribute ? $tenant->{$attribute} : $tenant->getKey());
    }

    public function serverSlug(): string
    {
        return Str::slug(Agents::name()) ?: 'assistant';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('Create token'))
                ->icon(Heroicon::OutlinedKey)
                ->modalHeading(__('Create an agent access token'))
                ->schema([
                    TextInput::make('label')->label(__('Label'))->placeholder('Claude Code on my laptop')->required()->maxLength(60),
                    CheckboxList::make('abilities')->label(__('Allowed to'))
                        ->options(['read' => __('Read: look things up, reports'), 'write' => __('Write: change data through the tools (still limited by your role)')])
                        ->default(['read'])->required(),
                ])
                ->action(function (array $data): void {
                    $abilities = array_values($data['abilities']);
                    if ($slug = $this->slug()) {
                        $abilities[] = 'tenant:'.$slug;
                    }
                    $this->plainTextToken = auth()->user()->createToken(Str::limit(trim($data['label']), 60, ''), $abilities)->plainTextToken;
                    Notification::make()->title(__('Token created — copy it now, it is shown once.'))->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $slug = $this->slug();

        return $table
            ->query(Sanctum::$personalAccessTokenModel::query()
                ->where('tokenable_type', auth()->user()?->getMorphClass())
                ->where('tokenable_id', auth()->id())
                ->when($slug, fn ($q) => $q->where('abilities', 'like', '%"tenant:'.$slug.'"%')))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('Label')),
                TextColumn::make('abilities')->label(__('Allowed to'))->badge()
                    ->formatStateUsing(fn ($state) => __(ucfirst((string) $state)))
                    ->state(fn ($record) => array_values(array_filter((array) $record->abilities, fn ($a) => ! str_starts_with((string) $a, 'tenant:')))),
                TextColumn::make('last_used_at')->label(__('Last used'))->since()->placeholder(__('never')),
                TextColumn::make('created_at')->label(__('Created'))->since(),
            ])
            ->recordActions([
                DeleteAction::make()->label(__('Revoke')),
            ])
            ->emptyStateHeading(__('No tokens yet'))
            ->emptyStateDescription(__('Create one and paste it into your agent.'));
    }
}
