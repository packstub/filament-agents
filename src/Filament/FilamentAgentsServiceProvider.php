<?php

namespace Packstub\Agents\Filament;

use Filament\Facades\Filament;
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Packstub\Agents\Chat\ChatAssets;
use Packstub\Agents\Filament\Widgets\TurnsChart;
use Packstub\Agents\Filament\Widgets\TurnStats;
use Packstub\Agents\Livewire\AgentTable;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * What a Filament panel adds to packstub/agents: the views, the chat's
 * stylesheet and Alpine component, the embedded resource table, the UI
 * strings — and the panels resolved before the engine registers its routes.
 * The engine itself (config, migrations, the MCP route, the poll route, the
 * commands) is registered by Packstub\Agents\AgentsServiceProvider.
 */
class FilamentAgentsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('packstub-filament-agents')
            ->hasViews('packstub-agents');
    }

    /** The provider sits one level below src/, so the package root is derived from here, not from the file's directory. */
    protected function getPackageBaseDir(): string
    {
        return dirname(__DIR__);
    }

    public function packageRegistered(): void
    {
        // Resolving the panels runs every plugin's register(): the context becomes the panel's and the server class is
        // mirrored into config before the engine's own booted callback registers the MCP and poll routes — a callback
        // queued while providers register runs before any queued while they boot, whatever the package order.
        $this->app->booted(function (): void {
            Livewire::component('packstub-agents.agent-table', AgentTable::class);
            Livewire::component('packstub-agents.turn-stats', TurnStats::class);
            Livewire::component('packstub-agents.turns-chart', TurnsChart::class);

            Filament::getPanels();
        });
    }

    public function packageBooted(): void
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../../resources/lang');

        Blade::anonymousComponentPath(__DIR__.'/../../resources/views/components', 'packstub-agents');

        // The chat's stylesheet and script are the engine's built files (packstub/agents, resources/dist); the plugin
        // adds the styles of what only a panel has (tables in answers, the slide-over, the tool picker) and the script
        // of the standalone composer. `php artisan filament:assets` publishes them all.
        FilamentAsset::register([
            Css::make('packstub-agents-chat', ChatAssets::path('agents-chat.css')),
            Css::make('packstub-agents', __DIR__.'/../../resources/css/agents.css'),
            AlpineComponent::make('agent-chat', ChatAssets::path('agent-chat.js')),
            AlpineComponent::make('agent-composer', __DIR__.'/../../resources/js/agent-composer.js'),
        ], 'packstub/filament-agents');
    }
}
