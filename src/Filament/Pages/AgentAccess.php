<?php

namespace Packstub\Agents\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Laravel\Sanctum\Sanctum;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Filament\Forms\ToolPicker;
use Packstub\Agents\Mcp\AgentTool;
use Packstub\Agents\Support\AgentTokens;

/**
 * Agent access: mint a token so Claude Code, Claude Desktop or any MCP
 * client can work inside this workspace as you (your role, this tenant).
 * The token is shown once. Its abilities are "read" / "write", optionally
 * "tool:{name}" for each tool it is limited to, and, with tenancy, the
 * workspace as "tenant:{slug}" so AuthenticateAgent can refuse it on any
 * other workspace. A token can also expire.
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
        return AgentTokens::mcpUrl($this->slug());
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
        return AgentTokens::serverSlug();
    }

    /**
     * The tools the signed-in person may run right now, as the picker shows them (AgentTokens::availableTools).
     *
     * @return array<string, array{title: string, description: string, readOnly: bool}>
     */
    public function availableTools(): array
    {
        return AgentTokens::availableTools();
    }

    /**
     * Every tool of the server by name => title, whatever the role allows (AgentTokens::toolTitles).
     *
     * @return array<string, string>
     */
    public function toolTitles(): array
    {
        return AgentTokens::toolTitles();
    }

    /** @return array<string, string> */
    public static function expiryOptions(): array
    {
        return AgentTokens::expiryOptions();
    }

    protected function getHeaderActions(): array
    {
        $tools = $this->availableTools();

        return [
            Action::make('create')
                ->label(__('Create token'))
                ->icon(Heroicon::OutlinedKey)
                ->modalHeading(__('Create an agent access token'))
                ->modalDescription(__('The token acts as you, in this workspace, within your role. Narrow it to what the agent needs.'))
                ->schema([
                    TextInput::make('label')->label(__('Label'))->placeholder('Claude Code on my laptop')->required()->maxLength(60),
                    CheckboxList::make('abilities')->label(__('Allowed to'))
                        ->options(['read' => __('Read: look things up, reports'), 'write' => __('Write: change data through the tools (still limited by your role)')])
                        ->default(['read'])->required()->live(),
                    Select::make('expires')->label(__('Expires'))
                        ->options(self::expiryOptions())->default('never')->required()->selectablePlaceholder(false)->native(false),
                    ToolPicker::make('tools')->label(__('Only these tools'))
                        ->helperText(__('Leave empty to allow every tool your role allows.'))
                        ->tools($tools)
                        ->writeEnabled(fn (Get $get) => in_array('write', (array) $get('abilities'), true))
                        ->visible($tools !== []),
                ])
                ->action(function (array $data): void {
                    // Scope: every ticked tool, as "tool:{name}"; a write tool only when the token may write. None ticked = every tool the role allows.
                    $this->plainTextToken = AgentTokens::mint(
                        auth()->user(),
                        (string) $data['label'],
                        array_values($data['abilities']),
                        array_values((array) ($data['tools'] ?? [])),
                        (string) ($data['expires'] ?? 'never'),
                        $this->slug(),
                    );
                    Notification::make()->title(__('Token created — copy it now, it is shown once.'))->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $slug = $this->slug();
        $titles = collect($this->toolTitles());

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
                    ->state(fn ($record) => array_values(array_filter((array) $record->abilities, fn ($a) => in_array($a, ['read', 'write'], true)))),
                TextColumn::make('tools')->label(__('Tools'))->badge()->color('gray')
                    ->state(fn ($record) => AgentTool::tokenTools($record) ?: [__('All tools')])
                    ->formatStateUsing(fn ($state) => $titles->get($state, $state))
                    ->listWithLineBreaks()->limitList(4)->expandableLimitedList(),
                TextColumn::make('last_used_at')->label(__('Last used'))->since()->placeholder(__('never')),
                TextColumn::make('expires_at')->label(__('Expires'))->placeholder(__('never'))
                    ->formatStateUsing(fn ($state) => $state ? ($state->isPast() ? __('Expired') : $state->diffForHumans()) : null)
                    ->color(fn ($state) => $state?->isPast() ? 'danger' : null),
                TextColumn::make('created_at')->label(__('Created'))->since(),
            ])
            ->recordActions([
                DeleteAction::make()->label(__('Revoke')),
            ])
            ->emptyStateHeading(__('No tokens yet'))
            ->emptyStateDescription(__('Create one and paste it into your agent.'));
    }
}
