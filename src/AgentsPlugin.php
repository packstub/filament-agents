<?php

namespace Packstub\Agents;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Tool;
use Packstub\Agents\Ai\Agent;
use Packstub\Agents\Contracts\AgentResource;
use Packstub\Agents\Filament\Pages\AgentAccess;
use Packstub\Agents\Filament\Pages\Chat;
use Packstub\Agents\Filament\Pages\Chats;
use Packstub\Agents\Filament\Pages\TurnLog;
use Packstub\Agents\Filament\Resources\AgentLimits\AgentLimitResource;
use Packstub\Agents\Http\Controllers\TurnController;

/**
 * Registers the assistant in a panel:
 *
 *   AgentsPlugin::make()
 *       ->name('Ask Acme')
 *       ->agent(AcmeAssistant::class)
 *       ->server(AcmeServer::class)
 *       ->authorizeUsing(fn (string $ability) => Access::can($ability))
 *
 * gives the panel the chat pages, the "Ask …" button and recent chats, and
 * the Agent access page (MCP tokens). An operator panel registers
 * AgentsPlugin::make()->chat(false)->agentAccess(false)->limits() for the
 * AI limits page only.
 */
class AgentsPlugin implements Plugin
{
    protected ?string $name = null;

    protected ?string $agent = null;

    protected ?string $server = null;

    /** @var list<class-string<Tool>> */
    protected array $tools = [];

    /** @var list<class-string<AgentResource>> */
    protected array $resources = [];

    /** @var list<class-string|object|Closure> */
    protected array $middleware = [];

    protected ?Closure $authorize = null;

    protected ?Closure $roleLabel = null;

    protected ?Closure $credentials = null;

    protected bool $chat = true;

    /** How a chat turn runs: 'queue' (a worker) or 'sync' (inside the request); null = config('packstub-agents.chat.driver'). */
    protected ?string $chatDriver = null;

    /** @var array<string, int|float> history.* overrides */
    protected array $history = [];

    protected bool $agentAccess = true;

    protected ?string $agentAccessAbility = null;

    protected Closure|string|null $agentAccessGroup = null;

    protected bool $limits = false;

    protected ?Closure $limitsAuthorize = null;

    /** The AI turns page; null = shown wherever the limits resource is. */
    protected ?bool $turnLog = null;

    /** @var list<string> */
    protected array $askButtonHiddenOn = [];

    public static function make(): static
    {
        return new static;
    }

    public function getId(): string
    {
        return 'packstub-agents';
    }

    /** How the assistant is called in the panel ("Ask Acme"). */
    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /** @param  class-string<Agent>  $class */
    public function agent(string $class): static
    {
        $this->agent = $class;

        return $this;
    }

    /** The MCP server class (name, instructions, tool list). @param  class-string<\Packstub\Agents\Mcp\AgentServer>  $class */
    public function server(string $class): static
    {
        $this->server = $class;

        return $this;
    }

    /** The tool list, when it is not declared on a server class. @param  list<class-string<\Laravel\Mcp\Server\Tool>>  $tools */
    public function tools(array $tools): static
    {
        $this->tools = $tools;

        return $this;
    }

    /** Explicit resources for show-table and page context (default: every panel resource implementing AgentResource). */
    public function resources(array $resources): static
    {
        $this->resources = $resources;

        return $this;
    }

    /**
     * The app's own agent middleware, run on every turn after the package's guard rails: classes with
     * handle(AgentPrompt $prompt, Closure $next), instances, or closures of that shape (see laravel/ai's
     * make:agent-middleware). Throw Packstub\Agents\Exceptions\TurnRefused to stop a turn with a message.
     *
     * @param  list<class-string|object|Closure>  $middleware
     */
    public function middleware(array $middleware): static
    {
        $this->middleware = $middleware;

        return $this;
    }

    /** How a tool's ability is checked for the current person: fn (string $ability): bool. */
    public function authorizeUsing(Closure $callback): static
    {
        $this->authorize = $callback;

        return $this;
    }

    /** The current person's role as a label, for the prompt and refusals: fn (): ?string. */
    public function roleLabelUsing(Closure $callback): static
    {
        $this->roleLabel = $callback;

        return $this;
    }

    /** Where a workspace's own provider, key and model come from: fn (): ?WorkspaceCredentials. */
    public function credentialsUsing(Closure $callback): static
    {
        $this->credentials = $callback;

        return $this;
    }

    /** The chat page. $driver picks how a turn runs: 'queue' hands the job to a worker, 'sync' runs it inside the request. */
    public function chat(bool $enabled = true, ?string $driver = null): static
    {
        $this->chat = $enabled;
        $this->chatDriver = $driver;

        return $this;
    }

    /**
     * What a long chat replays (config `history`): the window in estimated tokens, how many exchanges keep their
     * tool results verbatim, the share of the window from which the page suggests a new chat, the share from which
     * the context ring shows in the composer, and how many exchanges "Compress now" keeps.
     */
    public function history(?int $maxTokens = null, ?int $keepToolResultsTurns = null, ?float $noticeShare = null, ?float $meterShare = null, ?int $compressKeepTurns = null): static
    {
        $this->history = array_filter([
            'max_tokens' => $maxTokens,
            'keep_tool_results_turns' => $keepToolResultsTurns,
            'notice_share' => $noticeShare,
            'meter_share' => $meterShare,
            'compress_keep_turns' => $compressKeepTurns,
        ], fn ($value) => $value !== null);

        return $this;
    }

    /** The Agent access page (MCP tokens). $ability gates it; $group is its navigation group. */
    public function agentAccess(bool $enabled = true, ?string $ability = null, Closure|string|null $group = null): static
    {
        $this->agentAccess = $enabled;
        $this->agentAccessAbility = $ability;
        $this->agentAccessGroup = $group;

        return $this;
    }

    /** The operator's AI limits resource; $authorize decides who may edit it (default: any user of the panel). */
    public function limits(bool $enabled = true, ?Closure $authorize = null): static
    {
        $this->limits = $enabled;
        $this->limitsAuthorize = $authorize;

        return $this;
    }

    /**
     * The operator's AI turns page — every answer with who asked, the model, tokens, tools, duration and how it
     * ended. Shown with the limits resource by default and gated the same way; register it on the tenant panel
     * instead (limits(false, authorize: …)->turnLog()) when the turns live in a tenant database.
     */
    public function turnLog(bool $enabled = true): static
    {
        $this->turnLog = $enabled;

        return $this;
    }

    /** Route name patterns where the topbar "Ask …" button stays hidden, e.g. a home page that has its own composer. */
    public function hideAskButtonOn(array $routePatterns): static
    {
        $this->askButtonHiddenOn = $routePatterns;

        return $this;
    }

    public function register(Panel $panel): void
    {
        $manager = app(AgentsManager::class);

        if ($this->chat || $this->agentAccess) {
            config()->set('packstub-agents.panel', $panel->getId());
        }

        if ($this->name !== null) {
            config()->set('packstub-agents.name', $this->name);
        }

        if ($this->chatDriver !== null) {
            config()->set('packstub-agents.chat.driver', $this->chatDriver);
        }

        foreach ($this->history as $key => $value) {
            config()->set("packstub-agents.history.{$key}", $value);
        }

        if ($this->server !== null) {
            config()->set('packstub-agents.mcp.server', $this->server);
            $manager->useServer($this->server);
        }

        if ($this->agent !== null) {
            $manager->useAgent($this->agent);
        }

        if ($this->tools !== []) {
            $manager->useTools($this->tools);
        }

        if ($this->resources !== []) {
            $manager->useResources($this->resources);
        }

        if ($this->middleware !== []) {
            $manager->useMiddleware($this->middleware);
        }

        if ($this->authorize) {
            $manager->authorizeUsing($this->authorize);
        }

        if ($this->roleLabel) {
            $manager->roleLabelUsing($this->roleLabel);
        }

        if ($this->credentials) {
            $manager->credentialsUsing($this->credentials);
        }

        if ($this->limitsAuthorize) {
            $manager->limitsAuthorizeUsing($this->limitsAuthorize);
        }

        // Only the panel that shows the page decides its ability and group; an operator panel that switches the page
        // off (agentAccess(false)) must not reset them on the shared manager.
        if ($this->agentAccess) {
            $manager->agentAccess($this->agentAccessAbility, $this->agentAccessGroup);
        }

        if ($this->askButtonHiddenOn !== []) {
            $manager->hideAskButtonOn($this->askButtonHiddenOn);
        }

        $pages = [];

        if ($this->chat) {
            $pages[] = Chat::class;
            $pages[] = Chats::class;

            // The chat page polls this while an answer is produced; it runs under the panel's auth and tenant middleware.
            $panel->authenticatedTenantRoutes(fn () => Route::get('packstub-agents/chat/{conversation}/turn', TurnController::class)->name('packstub-agents.turn'));
        }

        if ($this->agentAccess) {
            $pages[] = AgentAccess::class;
        }

        if ($this->turnLog ?? $this->limits) {
            $pages[] = TurnLog::class;
        }

        if ($pages !== []) {
            $panel->pages($pages);
        }

        if ($this->limits) {
            $panel->resources([AgentLimitResource::class]);
        }
    }

    public function boot(Panel $panel): void
    {
        if (! $this->chat) {
            return;
        }

        // The "Ask …" button (with the record being viewed as context) and the recent chats in the sidebar.
        FilamentView::registerRenderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn (): string => view('packstub-agents::hooks.topbar')->render());
        FilamentView::registerRenderHook(PanelsRenderHook::SIDEBAR_NAV_END, fn (): string => view('packstub-agents::hooks.sidebar')->render());
    }
}
