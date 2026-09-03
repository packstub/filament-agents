<?php

namespace Packstub\Agents\Facades;

use Illuminate\Support\Facades\Facade;
use Packstub\Agents\AgentsManager;

/**
 * @method static string name()
 * @method static string|null panelId()
 * @method static \Filament\Panel|null panel()
 * @method static bool inPanel()
 * @method static \Illuminate\Database\Eloquent\Model|null tenant()
 * @method static class-string<\Packstub\Agents\Ai\Agent> agentClass()
 * @method static \Packstub\Agents\Ai\Agent agent(?string $pageContext = null, ?string $modelKey = null)
 * @method static class-string<\Packstub\Agents\Mcp\AgentServer> serverClass()
 * @method static list<class-string<\Laravel\Mcp\Server\Tool>> toolClasses()
 * @method static list<class-string<\Packstub\Agents\Contracts\AgentResource>> resourceClasses()
 * @method static bool allows(?string $ability)
 * @method static string|null roleLabel()
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
