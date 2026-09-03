<?php

namespace Packstub\Agents\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\AiServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Packstub\Agents\AgentsServiceProvider;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\AdminPanelProvider;
use Packstub\Agents\Tests\Fixtures\Models\User;
use Packstub\Agents\Tests\Fixtures\Models\Widget;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Abilities::reset();
        Filament::setCurrentPanel('admin');
    }

    protected function getPackageProviders($app): array
    {
        // Filament binds its own Livewire DataStore, so Support must register before Livewire (as package discovery orders them).
        return [
            SupportServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            ActionsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            QueryBuilderServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            LivewireServiceProvider::class,
            SanctumServiceProvider::class,
            AiServiceProvider::class,
            McpServiceProvider::class,
            AgentsServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('packstub-agents.enabled', true);
        $app['config']->set('packstub-agents.mcp.path', 'mcp');
        $app['config']->set('ai.providers.anthropic.key', 'sk-test');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel/sanctum/database/migrations');
    }

    protected function user(array $attributes = []): User
    {
        return User::query()->create($attributes + ['name' => 'Ada Lovelace', 'email' => uniqid().'@example.com', 'password' => 'secret']);
    }

    /** @return array<int, Widget> */
    protected function widgets(): array
    {
        return [
            Widget::query()->create(['name' => 'Alpha', 'status' => 'live', 'price' => 10]),
            Widget::query()->create(['name' => 'Beta', 'status' => 'draft', 'price' => 20]),
            Widget::query()->create(['name' => 'Gamma', 'status' => 'live', 'price' => 30]),
        ];
    }
}
