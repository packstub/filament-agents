<?php

namespace Packstub\Agents\Facades;

use Illuminate\Support\Facades\Facade;
use Packstub\Agents\AgentsManager;

/**
 * @method static string name()
 * @method static \Packstub\Agents\Contracts\AgentContext context()
 * @method static bool inPanel()
 * @method static \Illuminate\Database\Eloquent\Model|null tenant()
 * @method static void tenantUsing(\Closure $resolve, ?\Closure $enter = null)
 * @method static \Closure|null tenantResolver()
 * @method static \Closure|null tenantEnterHook()
 * @method static void tenantModel(string $model, ?string $slugAttribute = null)
 * @method static class-string<\Illuminate\Database\Eloquent\Model>|null tenantModelClass()
 * @method static string|null tenantSlugAttribute()
 * @method static void useAgent(string $class)
 * @method static class-string<\Packstub\Agents\Ai\Agent> agentClass()
 * @method static \Packstub\Agents\Ai\Agent agent(?string $pageContext = null, ?string $modelKey = null)
 * @method static void useServer(string $class)
 * @method static class-string<\Packstub\Agents\Mcp\AgentServer> serverClass()
 * @method static void useTools(array $tools)
 * @method static void addTools(array|\Closure $tools)
 * @method static list<class-string<\Laravel\Mcp\Server\Tool>> toolClasses()
 * @method static void useResources(array $resources)
 * @method static list<class-string<\Packstub\Agents\Contracts\AgentResource>> resourceClasses()
 * @method static list<class-string<\Packstub\Agents\Contracts\AgentResource>> registeredResources()
 * @method static void useMiddleware(array $middleware)
 * @method static list<object|\Closure> middleware()
 * @method static void authorizeUsing(\Closure $callback)
 * @method static bool allows(?string $ability)
 * @method static void roleLabelUsing(\Closure $callback)
 * @method static string|null roleLabel()
 * @method static void credentialsUsing(\Closure $callback)
 * @method static \Packstub\Agents\Ai\WorkspaceCredentials|null credentials()
 * @method static bool canManageLimits()
 * @method static string|null agentAccessAbility()
 * @method static string|null agentAccessGroup()
 * @method static list<string> askButtonHiddenOn()
 *
 * @see AgentsManager
 */
class Agents extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AgentsManager::class;
    }
}
