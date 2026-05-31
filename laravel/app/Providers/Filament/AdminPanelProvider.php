<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Profile\ApiTokensPage;
use App\Filament\Pages\Profile\ProfilePage;
use App\Filament\Pages\Profile\SecurityPage;
use App\Filament\Pages\Tenancy\EditWorkspaceProfile;
use App\Filament\Widgets\AgentRunStatsOverview;
use App\Filament\Widgets\RecentContactsTable;
use App\Filament\Widgets\RecentFailedRunsTable;
use App\Filament\Widgets\RecentLeadsStatsOverview;
use App\Filament\Widgets\RunsThroughputChart;
use App\Filament\Widgets\WaitingHumanRunsTable;
use App\Http\Middleware\RedirectToRegisterIfNoUsers;
use App\Models\Workspace;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName('Oryntra')
            ->brandLogo(fn (): HtmlString => new HtmlString(
                '<span style="display: inline-flex; align-items: center; gap: 0.625rem;">'
                . '<img src="' . e(asset('favicon_io/favicon-32x32.png')) . '" alt="" style="width: 1.5rem; height: 1.5rem;">'
                . '<span>Oryntra</span>'
                . '</span>'
            ))
            ->brandLogoHeight('1.5rem')
            ->favicon(asset('favicon_io/favicon.ico'))
            ->tenant(Workspace::class)
            ->tenantProfile(EditWorkspaceProfile::class)
            ->searchableTenantMenu()
            ->globalSearch(false)
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
                ProfilePage::class,
                SecurityPage::class,
                ApiTokensPage::class,
            ])
            ->userMenuItems([
                MenuItem::make()
                    ->label('Meu perfil')
                    ->icon('heroicon-o-user-circle')
                    ->visible(fn (): bool => filament()->getTenant() !== null)
                    ->url(fn (): string => filament()->getTenant() ? ProfilePage::getUrl() : '#'),
                MenuItem::make()
                    ->label('Segurança')
                    ->icon('heroicon-o-shield-check')
                    ->visible(fn (): bool => filament()->getTenant() !== null)
                    ->url(fn (): string => filament()->getTenant() ? SecurityPage::getUrl() : '#'),
                MenuItem::make()
                    ->label('Tokens da API')
                    ->icon('heroicon-o-key')
                    ->visible(fn (): bool => filament()->getTenant() !== null)
                    ->url(fn (): string => filament()->getTenant() ? ApiTokensPage::getUrl() : '#'),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AgentRunStatsOverview::class,
                WaitingHumanRunsTable::class,
                RunsThroughputChart::class,
                RecentFailedRunsTable::class,
                RecentLeadsStatsOverview::class,
                RecentContactsTable::class,
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
                RedirectToRegisterIfNoUsers::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
