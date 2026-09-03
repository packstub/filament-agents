<?php

namespace Packstub\Agents;

use Closure;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Server\Tool;
use Packstub\Agents\Ai\Agent;
use Packstub\Agents\Ai\DefaultAgent;
use Packstub\Agents\Ai\WorkspaceCredentials;
use Packstub\Agents\Contracts\AgentResource;
use Packstub\Agents\Mcp\AgentServer;
use ReflectionClass;

/**
 * What the app told the package about itself: the agent class, the tool list,
 * how to authorize an ability, where a workspace's own provider key comes
 * from. AgentsPlugin fills it in when the panel registers; everything else
 * in the package reads it through the Agents facade.
 */
class AgentsManager
{
    /** @var class-string<Agent>|null */
    protected ?string $agent = null;

    /** @var class-string<AgentServer>|null */
    protected ?string $server = null;

    /** @var list<class-string<Tool>> */
    protected array $tools = [];

    /** @var list<class-string<AgentResource>> */
    protected array $resources = [];

    protected ?Closure $authorize = null;

    protected ?Closure $roleLabel = null;

    protected ?Closure $credentials = null;

    protected ?Closure $limitsAuthorize = null;

    protected ?string $agentAccessAbility = null;

    protected Closure|string|null $agentAccessGroup = null;

    /** @var list<string> */
    protected array $askButtonHiddenOn = ['*.pages.chat', '*.pages.chat.*'];

    /** How the assistant is called in the panel ("Ask Orderflux"). */
    public function name(): string
    {
        return (string) config('packstub-agents.name', 'Assistant');
    }

    public function panelId(): ?string
    {
        return config('packstub-agents.panel');
    }

    /** The panel the assistant lives in: the current one when it matches, otherwise the registered one. */
    public function panel(): ?Panel
    {
        $current = Filament::getCurrentPanel();
        $id = $this->panelId();

        if ($current && (! $id || $current->getId() === $id)) {
            return $current;
        }

        return $id && Filament::hasPanel($id) ? Filament::getPanel($id) : $current;
    }

    /** True when the request is served inside the assistant's panel (chat, hooks and agent access show up). */
    public function inPanel(): bool
    {
        $panel = Filament::getCurrentPanel();

        if (! $panel || ($this->panelId() && $panel->getId() !== $this->panelId())) {
            return false;
        }

        return ! $panel->hasTenancy() || Filament::getTenant() !== null;
    }

    /** The workspace the request runs in (Filament's tenant), if the panel has tenancy. */
    public function tenant(): ?Model
    {
        return Filament::getTenant();
    }

    /** @param  class-string<Agent>  $class */
    public function useAgent(string $class): void
    {
        $this->agent = $class;
    }

    /** @return class-string<Agent> */
    public function agentClass(): string
    {
        return $this->agent ?? DefaultAgent::class;
    }

    public function agent(?string $pageContext = null, ?string $modelKey = null): Agent
    {
        $class = $this->agentClass();

        return new $class($pageContext, $modelKey);
    }

    /** @param  class-string<AgentServer>  $class */
    public function useServer(string $class): void
    {
        $this->server = $class;
    }

    /** @return class-string<AgentServer> */
    public function serverClass(): string
    {
        return $this->server ?? config('packstub-agents.mcp.server') ?? AgentServer::class;
    }

    /** @param  list<class-string<Tool>>  $tools */
    public function useTools(array $tools): void
    {
        $this->tools = array_values($tools);
    }

    /**
     * Every tool of the product, in the order the model sees them: the list
     * given to the plugin, or the default $tools of the MCP server class.
     *
     * @return list<class-string<Tool>>
     */
    public function toolClasses(): array
    {
        if ($this->tools !== []) {
            return $this->tools;
        }

        $server = $this->serverClass();

        return array_values((new ReflectionClass($server))->getDefaultProperties()['tools'] ?? []);
    }

    /** @param  list<class-string<AgentResource>>  $resources */
    public function useResources(array $resources): void
    {
        $this->resources = array_values($resources);
    }

    /**
     * The Filament resources the assistant may show as live tables and use as
     * page context: the list given to the plugin, or every resource of the
     * panel that implements AgentResource.
     *
     * @return list<class-string<AgentResource>>
     */
    public function resourceClasses(): array
    {
        if ($this->resources !== []) {
            return $this->resources;
        }

        $panel = $this->panel();

        return $panel ? array_values(array_filter($panel->getResources(), fn (string $r) => is_subclass_of($r, AgentResource::class))) : [];
    }

    public function authorizeUsing(Closure $callback): void
    {
        $this->authorize = $callback;
    }

    /**
     * May the current person do this? null = any member. Without a callback
     * the ability goes through the Gate when one is defined and is otherwise
     * allowed, so an app without abilities still works out of the box.
     */
    public function allows(?string $ability): bool
    {
        if ($ability === null || $ability === '') {
            return true;
        }

        if ($this->authorize) {
            return (bool) ($this->authorize)($ability);
        }

        return Gate::has($ability) ? Gate::allows($ability) : true;
    }

    public function roleLabelUsing(Closure $callback): void
    {
        $this->roleLabel = $callback;
    }

    /** The current person's role as a label ("Warehouse"), for prompts and refusals; null when the app has no roles. */
    public function roleLabel(): ?string
    {
        $label = $this->roleLabel ? ($this->roleLabel)() : null;

        return $label !== null && $label !== '' ? (string) $label : null;
    }

    public function credentialsUsing(Closure $callback): void
    {
        $this->credentials = $callback;
    }

    /** The workspace's own provider, key and preferred model, when it brought its own. */
    public function credentials(): ?WorkspaceCredentials
    {
        $credentials = $this->credentials ? ($this->credentials)() : null;

        return $credentials instanceof WorkspaceCredentials ? $credentials : null;
    }

    public function limitsAuthorizeUsing(Closure $callback): void
    {
        $this->limitsAuthorize = $callback;
    }

    /** May the current user edit the operator's AI limits? */
    public function canManageLimits(): bool
    {
        return $this->limitsAuthorize ? (bool) ($this->limitsAuthorize)() : auth()->check();
    }

    public function agentAccess(?string $ability, Closure|string|null $group): void
    {
        $this->agentAccessAbility = $ability;
        $this->agentAccessGroup = $group;
    }

    public function agentAccessAbility(): ?string
    {
        return $this->agentAccessAbility;
    }

    /** Route name patterns where the topbar "Ask …" button stays hidden (the chat itself, a home page with its own composer). */
    public function hideAskButtonOn(array $patterns): void
    {
        $this->askButtonHiddenOn = array_values(array_unique([...$this->askButtonHiddenOn, ...$patterns]));
    }

    /** @return list<string> */
    public function askButtonHiddenOn(): array
    {
        return $this->askButtonHiddenOn;
    }

    public function agentAccessGroup(): ?string
    {
        $group = $this->agentAccessGroup instanceof Closure ? ($this->agentAccessGroup)() : $this->agentAccessGroup;

        return $group !== null ? (string) $group : null;
    }
}
