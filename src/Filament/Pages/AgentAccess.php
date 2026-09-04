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
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tool;
use Laravel\Sanctum\Sanctum;
use Packstub\Agents\Facades\Agents;
use Packstub\Agents\Mcp\AgentTool;

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

    /**
     * The tools the signed-in person may run right now, as the picker shows
     * them: name => [title, description, read-only]. A token can only be
     * limited to tools the role allows; the role is checked again on every
     * call, so a later role change still wins.
     *
     * @return array<string, array{title: string, description: string, readOnly: bool}>
     */
    public function availableTools(): array
    {
        $tools = [];

        foreach (Agents::toolClasses() as $class) {
            /** @var Tool $tool */
            $tool = app($class);

            if (! $tool->eligibleForRegistration()) {
                continue;
            }

            $tools[$tool->name()] = [
                'title' => $tool->title(),
                'description' => $tool->description(),
                'readOnly' => $tool instanceof AgentTool ? $tool->isReadOnly() : AgentTool::hasReadOnlyAnnotation($tool),
            ];
        }

        return $tools;
    }

    /**
     * Every tool of the server by name => title, whatever the role allows,
     * so the table can name a tool a token was scoped to even after the role
     * lost it.
     *
     * @return array<string, string>
     */
    public function toolTitles(): array
    {
        $titles = [];

        foreach (Agents::toolClasses() as $class) {
            /** @var Tool $tool */
            $tool = app($class);
            $titles[$tool->name()] = $tool->title();
        }

        return $titles;
    }

    /** @return array<string, string> */
    public static function expiryOptions(): array
    {
        return [
            'never' => __('Never'),
            '7' => __(':days days', ['days' => 7]),
            '30' => __(':days days', ['days' => 30]),
            '90' => __(':days days', ['days' => 90]),
            '365' => __(':days days', ['days' => 365]),
        ];
    }

    protected function getHeaderActions(): array
    {
        $tools = $this->availableTools();
        $options = fn (bool $readOnly) => collect($tools)->filter(fn ($t) => $t['readOnly'] === $readOnly);

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
                    CheckboxList::make('read_tools')->label(__('Only these read tools'))
                        ->helperText(__('Leave empty to allow every tool your role allows.'))
                        ->options($options(true)->map(fn ($t) => $t['title'])->all())
                        ->descriptions($options(true)->map(fn ($t) => $t['description'])->all())
                        ->columns(2)->bulkToggleable()
                        ->visible($options(true)->isNotEmpty()),
                    CheckboxList::make('write_tools')->label(__('Only these write tools'))
                        ->helperText(__('Leave empty to allow every write tool your role allows.'))
                        ->options($options(false)->map(fn ($t) => $t['title'])->all())
                        ->descriptions($options(false)->map(fn ($t) => $t['description'])->all())
                        ->columns(2)->bulkToggleable()
                        ->visible(fn (Get $get) => $options(false)->isNotEmpty() && in_array('write', (array) $get('abilities'), true)),
                ])
                ->action(function (array $data): void {
                    $abilities = array_values($data['abilities']);
                    $canWrite = in_array('write', $abilities, true);

                    // Scope: every ticked tool, as "tool:{name}"; a write tool only when the token may write. None ticked = every tool the role allows.
                    $scoped = array_merge(
                        array_values((array) ($data['read_tools'] ?? [])),
                        $canWrite ? array_values((array) ($data['write_tools'] ?? [])) : [],
                    );
                    $known = array_keys($this->availableTools());
                    foreach (array_intersect($scoped, $known) as $name) {
                        $abilities[] = 'tool:'.$name;
                    }

                    if ($slug = $this->slug()) {
                        $abilities[] = 'tenant:'.$slug;
                    }

                    $days = (string) ($data['expires'] ?? 'never');
                    $expiresAt = $days !== 'never' && ctype_digit($days) ? now()->addDays((int) $days) : null;

                    $this->plainTextToken = auth()->user()->createToken(Str::limit(trim($data['label']), 60, ''), $abilities, $expiresAt)->plainTextToken;
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
