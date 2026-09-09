<?php

namespace Packstub\Agents;

use Filament\Facades\Filament;
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Mcp\Facades\Mcp;
use Livewire\Livewire;
use Packstub\Agents\Commands\MakeAgentCommand;
use Packstub\Agents\Commands\MakeToolCommand;
use Packstub\Agents\Contracts\AgentContext;
use Packstub\Agents\Http\Controllers\TurnController;
use Packstub\Agents\Http\Middleware\AcceptJson;
use Packstub\Agents\Livewire\AgentTable;
use Packstub\Agents\Support\AgentConversationStore;
use Packstub\Agents\Support\Context\LaravelContext;
use Packstub\Agents\Support\Installed;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AgentsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('packstub-agents')
            ->hasConfigFile()
            ->hasViews('packstub-agents')
            ->discoversMigrations()
            // Auto-run by default; database-per-tenant apps set run_migrations=false, publish and split them.
            ->runsMigrations((bool) config('packstub-agents.run_migrations', true))
            ->hasCommand(MakeAgentCommand::class)
            ->hasCommand(MakeToolCommand::class)
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->startWith(fn (InstallCommand $command) => $command->info('Installing Packstub Agents…'))
                    ->publishConfigFile()
                    ->askToRunMigrations()
                    ->endWith(function (InstallCommand $command): void {
                        $command->call('packstub-agents:agent');

                        if (Installed::filament()) {
                            $command->info('Next: register the plugin in your panel provider —');
                            $command->line('    ->plugin(\Packstub\Agents\AgentsPlugin::make()->name(\'Ask …\')->agent(\App\Ai\Agents\Assistant::class)->tools([...]))');
                            $command->line('add a provider key to .env (ANTHROPIC_API_KEY, OPENAI_API_KEY, GEMINI_API_KEY or XAI_API_KEY, with AGENT_PROVIDER), run `php artisan filament:assets`,');
                            $command->line('and add the package views to your theme: @source \'../../../../vendor/packstub/filament-agents/resources/views\';');
                        } else {
                            $command->info('Next: register the agent and the tools in a service provider —');
                            $command->line('    Agents::useAgent(\App\Ai\Agents\Assistant::class); Agents::useTools([...]);');
                            $command->line('and add a provider key to .env (ANTHROPIC_API_KEY, OPENAI_API_KEY, GEMINI_API_KEY or XAI_API_KEY, with AGENT_PROVIDER).');
                        }
                    });
            });
    }

    /** Deep-merge the package's config defaults under any user-published values (mergeConfigFrom is top-level only). */
    public function packageRegistered(): void
    {
        $defaults = require __DIR__.'/../config/packstub-agents.php';

        config()->set('packstub-agents', array_replace_recursive($defaults, config('packstub-agents', [])));

        $this->app->singleton(AgentsManager::class);

        // Who is acting and where: a plain Laravel app's guard and workspace closure; AgentsPlugin rebinds the panel's.
        $this->app->singleton(AgentContext::class, LaravelContext::class);
    }

    public function packageBooted(): void
    {
        // The chat records a question before the provider answers it (see AgentConversationStore).
        $this->app->singleton(ConversationStore::class, fn (): AgentConversationStore => new AgentConversationStore(config('ai.conversations.connection')));
        $this->app->alias(ConversationStore::class, AgentConversationStore::class);

        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');

        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'packstub-agents');

        // The chat's stylesheet and Alpine component, and the embedded resource table, exist only in a panel.
        if (Installed::filament()) {
            FilamentAsset::register([
                Css::make('packstub-agents', __DIR__.'/../resources/css/agents.css'),
                AlpineComponent::make('agent-chat', __DIR__.'/../resources/js/agent-chat.js'),
            ], 'packstub/filament-agents');
        }

        // Livewire and the panels may boot after this provider; the registrations wait for the whole app.
        $this->app->booted(function (): void {
            if (Installed::filament()) {
                Livewire::component('packstub-agents.agent-table', AgentTable::class);

                // Resolving the panels runs every plugin's register(): the context becomes the panel's and the server
                // class is mirrored into config, before any route or job reads either. Without Filament, config is the source.
                Filament::getPanels();
            }

            $this->registerMcpRoute();
            $this->registerTurnRoute();
        });
    }

    /**
     * POST {mcp.path} with "Authorization: Bearer <agent token>". Registered
     * once every panel (and so the plugin's server choice) is known.
     */
    protected function registerMcpRoute(): void
    {
        if (! (bool) config('packstub-agents.mcp.enabled', true)) {
            return;
        }

        if (! $this->app->runningInConsole() && $this->app->routesAreCached()) {
            return;
        }

        // Whatever the app configures, a client that fails authentication gets a JSON 401, never a login redirect.
        Mcp::web('/'.trim((string) config('packstub-agents.mcp.path', 'mcp'), '/'), app(AgentsManager::class)->serverClass())
            ->where('tenant', '[A-Za-z0-9-]+')
            ->middleware([AcceptJson::class, ...config('packstub-agents.mcp.middleware', [])]);
    }

    /**
     * GET {chat.path}/chat/{conversation}/turn — what a chat polls while an
     * answer is produced — outside a panel, under chat.middleware. A panel
     * that registered the chat has its own (packstub-agents.turn on the
     * panel's authenticated tenant routes) and this one is left out.
     */
    protected function registerTurnRoute(): void
    {
        if (! $this->app->runningInConsole() && $this->app->routesAreCached()) {
            return;
        }

        foreach (array_keys(Route::getRoutes()->getRoutesByName()) as $name) {
            if (str_ends_with($name, '.packstub-agents.turn')) {
                return;
            }
        }

        Route::get(trim((string) config('packstub-agents.chat.path', 'agents'), '/').'/chat/{conversation}/turn', TurnController::class)
            ->middleware((array) config('packstub-agents.chat.middleware', ['web', 'auth']))
            ->name('packstub-agents.turn');
    }
}
