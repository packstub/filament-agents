<?php

namespace Packstub\Agents\Tests;

use Filament\FilamentServiceProvider;
use Filament\Support\SupportServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Packstub\Agents\AgentsServiceProvider;
use Packstub\Agents\Testing\InteractsWithAgentTokens;
use Packstub\Agents\Tests\Fixtures\Models\User;
use Packstub\Agents\Tests\Fixtures\Tools\DeleteWidget;
use Packstub\Agents\Tests\Fixtures\Tools\ListWidgets;
use Packstub\Agents\Tests\Fixtures\Tools\UpdateWidget;

abstract class TestCase extends Orchestra
{
    use InteractsWithAgentTokens;

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            SupportServiceProvider::class,
            FilamentServiceProvider::class,
            McpServiceProvider::class,
            AgentsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('auth.providers.users.model', User::class);

        $app['config']->set('packstub-agents.tools', [
            ListWidgets::class,
            UpdateWidget::class,
            DeleteWidget::class,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
