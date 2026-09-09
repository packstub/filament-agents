<?php

namespace Packstub\Agents\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\AiServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Packstub\Agents\AgentsServiceProvider;
use Packstub\Agents\Support\AgentLimits;
use Packstub\Agents\Tests\Fixtures\Abilities;
use Packstub\Agents\Tests\Fixtures\Models\Team;
use Packstub\Agents\Tests\Fixtures\Models\User;
use Packstub\Agents\Tests\Fixtures\Models\Widget;

/**
 * The package in a plain Laravel app: no Filament providers, no Livewire, no
 * panel — Sanctum, laravel/ai, laravel/mcp and the package alone. Filament's
 * classes are still on the autoloader (it is a dev dependency), which is why
 * the package checks for its provider, not its classes.
 */
abstract class HeadlessTestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Abilities::reset();
        AgentLimits::flush();
    }

    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            AiServiceProvider::class,
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
            'foreign_key_constraints' => false,
        ]);

        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('packstub-agents.name', 'Ask Widgets');
        $app['config']->set('packstub-agents.enabled', true);
        $app['config']->set('packstub-agents.provider', 'anthropic');
        $app['config']->set('ai.providers.anthropic.key', 'sk-test');
        $app['config']->set('packstub-agents.models', [
            'anthropic' => [
                'auto' => ['label' => 'Auto', 'model' => 'test-claude-auto', 'effort' => 'medium'],
                'fast' => ['label' => 'Fast', 'model' => 'test-claude-fast', 'effort' => null],
            ],
        ]);
        $app['config']->set('queue.default', 'sync');
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

    protected function team(User $owner, string $slug = 'acme'): Team
    {
        return Team::query()->create(['owner_id' => $owner->id, 'name' => ucfirst($slug), 'slug' => $slug]);
    }

    /** @return array<int, Widget> */
    protected function widgets(): array
    {
        return [
            Widget::query()->create(['name' => 'Alpha', 'status' => 'live', 'price' => 10]),
            Widget::query()->create(['name' => 'Beta', 'status' => 'draft', 'price' => 20]),
        ];
    }
}
