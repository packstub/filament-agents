<?php

namespace Packstub\Agents;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Laravel\Mcp\Server\Tool;
use Packstub\Agents\Ai\Agent;
use Packstub\Agents\Contracts\AgentResource;
use Packstub\Agents\Filament\Pages\AgentAccess;
use Packstub\Agents\Filament\Pages\Chat;
use Packstub\Agents\Filament\Pages\Chats;
use Packstub\Agents\Filament\Resources\AgentLimits\AgentLimitResource;

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

    protected ?Closure $authorize = null;

    protected ?Closure $roleLabel = null;

    protected ?Closure $credentials = null;

    protected bool $chat = true;

    protected bool $agentAccess = true;

    protected ?string $agentAccessAbility = null;

    protected Closure|string|null $agentAccessGroup = null;

    protected bool $limits = false;

    protected ?Closure $limitsAuthorize = null;

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

    /** Explicit resources for show_table and page context (default: every panel resource implementing AgentResource). */
    public function resources(array $resources): static
    {
        $this->resources = $resources;

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

    public function chat(bool $enabled = true): static
    {
        $this->chat = $enabled;

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

        $manager->agentAccess($this->agentAccessAbility, $this->agentAccessGroup);

        if ($this->askButtonHiddenOn !== []) {
            $manager->hideAskButtonOn($this->askButtonHiddenOn);
        }

        $pages = [];

        if ($this->chat) {
            $pages[] = Chat::class;
            $pages[] = Chats::class;
        }

        if ($this->agentAccess) {
            $pages[] = AgentAccess::class;
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
