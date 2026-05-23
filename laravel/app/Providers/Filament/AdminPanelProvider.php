<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Tenancy\RegisterWorkspace;
use App\Filament\Widgets\AgentRunStatsOverview;
use App\Filament\Widgets\RecentContactsTable;
use App\Filament\Widgets\RecentFailedRunsTable;
use App\Filament\Widgets\RecentLeadsStatsOverview;
use App\Filament\Widgets\RunsThroughputChart;
use App\Filament\Widgets\WaitingHumanRunsTable;
use App\Models\Workspace;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('Oryntra')
            ->tenant(Workspace::class)
            ->tenantRegistration(RegisterWorkspace::class)
            ->searchableTenantMenu()
            ->sidebarCollapsibleOnDesktop()
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->navigationGroups([
                'Agentes',
                'Contatos',
                'Chatwoot',
                'Plataforma',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                AgentRunStatsOverview::class,
                WaitingHumanRunsTable::class,
                RunsThroughputChart::class,
                RecentFailedRunsTable::class,
                RecentLeadsStatsOverview::class,
                RecentContactsTable::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
