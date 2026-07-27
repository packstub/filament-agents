<?php

namespace Packstub\Agents;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Mcp\Facades\Mcp;
use Packstub\Agents\Commands\CreateAgentTokenCommand;
use Packstub\Agents\Http\Middleware\AuthenticateAgentToken;
use Packstub\Agents\Mcp\AgentServer;
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
            ->discoversMigrations()
            // Hybrid migration strategy: auto-run by default; consumers who need
            // a custom schema set run_migrations=false and publish instead
            // (vendor:publish --tag=packstub-agents-migrations).
            ->runsMigrations((bool) config('packstub-agents.run_migrations', true))
            ->hasCommand(CreateAgentTokenCommand::class)
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->startWith(fn (InstallCommand $command) => $command->info('🤖  Installing Packstub Agents…'))
                    ->publishConfigFile()
                    ->askToRunMigrations()
                    ->endWith(fn (InstallCommand $command) => $command->info(
                        'Next: register the plugin in your panel provider'
                        .' (->plugin(\Packstub\Agents\AgentsPlugin::make())), list your tools in'
                        .' config/packstub-agents.php, then create a token with'
                        .' `php artisan packstub:agents:create-token`.'
                    ));
            });
    }

    /**
     * Deep-merge the package's config defaults under any user-published values,
     * so a slim published config cannot silently drop nested defaults.
     */
    public function packageRegistered(): void
    {
        $defaults = require __DIR__.'/../config/packstub-agents.php';

        config()->set(
            'packstub-agents',
            array_replace_recursive($defaults, config('packstub-agents', [])),
        );
    }

    public function packageBooted(): void
    {
        $this->registerRateLimiter();
        $this->registerMcpRoute();
    }

    protected function registerRateLimiter(): void
    {
        RateLimiter::for('packstub-agents', function (Request $request): Limit {
            $bearerToken = (string) $request->bearerToken();

            return Limit::perMinute((int) config('packstub-agents.rate_limit.per_minute', 120))
                ->by($bearerToken !== '' ? 'token:'.Str::before($bearerToken, '.') : 'ip:'.$request->ip())
                ->response(fn (Request $request, array $headers): JsonResponse => response()->json([
                    'message' => 'Too many MCP requests for this agent token.',
                ], 429, $headers));
        });
    }

    protected function registerMcpRoute(): void
    {
        if (! (bool) config('packstub-agents.enabled', true)) {
            return;
        }

        // Mirror laravel/mcp's own guard: when routes are cached at runtime the
        // cached table already contains this route; during route:cache (console)
        // we must still register so the rebuilt cache includes it.
        if (! $this->app->runningInConsole() && $this->app->routesAreCached()) {
            return;
        }

        Mcp::web((string) config('packstub-agents.route.path', '/mcp/agents'), AgentServer::class)
            ->middleware(array_merge(
                ['throttle:packstub-agents', AuthenticateAgentToken::class],
                config('packstub-agents.route.middleware', []),
            ));
    }
}
