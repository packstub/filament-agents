<?php

namespace Packstub\Agents\Tests\Fixtures;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Packstub\Agents\AgentsPlugin;
use Packstub\Agents\Tests\Fixtures\Filament\Resources\Widgets\WidgetResource;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->resources([WidgetResource::class])
            ->pages([Dashboard::class])
            ->plugin(
                AgentsPlugin::make()
                    ->name('Ask Widgets')
                    ->agent(WidgetAgent::class)
                    ->server(WidgetServer::class)
                    ->authorizeUsing(fn (string $ability) => Abilities::allows($ability))
                    ->roleLabelUsing(fn () => Abilities::$role)
                    ->agentAccess(ability: 'setup.view', group: 'Setup')
                    ->limits(authorize: fn () => (bool) auth()->user()?->is_admin)
                    ->hideAskButtonOn(['*.pages.dashboard']),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
