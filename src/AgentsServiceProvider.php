<?php

namespace Packstub\Agents;

use Filament\Facades\Filament;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Blade;
use Laravel\Mcp\Facades\Mcp;
use Livewire\Livewire;
use Packstub\Agents\Commands\MakeAgentCommand;
use Packstub\Agents\Commands\MakeToolCommand;
use Packstub\Agents\Http\Middleware\AcceptJson;
use Packstub\Agents\Livewire\AgentTable;
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
                        $command->info('Next: register the plugin in your panel provider —');
                        $command->line('    ->plugin(\Packstub\Agents\AgentsPlugin::make()->name(\'Ask …\')->agent(\App\Ai\Agents\Assistant::class)->tools([...]))');
                        $command->line('add a provider key to .env (ANTHROPIC_API_KEY or OPENAI_API_KEY), run `php artisan filament:assets`,');
                        $command->line('and add the package views to your theme: @source \'../../../../vendor/packstub/filament-agents/resources/views\';');
                    });
            });
    }

    /** Deep-merge the package's config defaults under any user-published values (mergeConfigFrom is top-level only). */
    public function packageRegistered(): void
    {
        $defaults = require __DIR__.'/../config/packstub-agents.php';

        config()->set('packstub-agents', array_replace_recursive($defaults, config('packstub-agents', [])));

        $this->app->singleton(AgentsManager::class);
    }

    public function packageBooted(): void
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');

        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'packstub-agents');

        FilamentAsset::register([
            Css::make('packstub-agents', __DIR__.'/../resources/css/agents.css'),
        ], 'packstub/filament-agents');

        // Livewire and the panels may boot after this provider; both registrations wait for the whole app.
        $this->app->booted(function (): void {
            Livewire::component('packstub-agents.agent-table', AgentTable::class);
            $this->registerMcpRoute();
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

        // Resolving the panels runs every plugin's register(), which mirrors the server class into config.
        Filament::getPanels();

        // Whatever the app configures, a client that fails authentication gets a JSON 401, never a login redirect.
        Mcp::web('/'.trim((string) config('packstub-agents.mcp.path', 'mcp'), '/'), app(AgentsManager::class)->serverClass())
            ->where('tenant', '[A-Za-z0-9-]+')
            ->middleware([AcceptJson::class, ...config('packstub-agents.mcp.middleware', [])]);
    }
}
