<?php

namespace App\Providers\Filament;

use App\Filament\Plugins\AppEscalatedFilamentPlugin;
use App\Http\Middleware\AuthenticateFilament;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use LaraZeus\SpatieTranslatable\SpatieTranslatablePlugin;
use Livewire\Mechanisms\FrontendAssets\FrontendAssets;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->default()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->plugin(
                AppEscalatedFilamentPlugin::make()
                    ->navigationGroup('Support')
                    ->agentGate('escalated-agent')
                    ->adminGate('escalated-admin')
            )
            ->plugin(
                SpatieTranslatablePlugin::make()
                    ->defaultLocales(is_array($l = config('app.available_locales', ['en'])) ? $l : ['en'])
            )
            // Livewire core for the panel surface (2026-11-22). The app
            // layout's single-boot fix (47282e13) bundles Livewire+Alpine
            // into app.js and globally disables Livewire's auto-injection
            // (config/livewire.php inject_assets=false) — but the Filament
            // panel layout never loads app.js, and @filamentScripts ships
            // only Filament's own Alpine/plugins, NOT the Livewire runtime.
            // Result (live 2026-11-21→22): every admin page rendered chrome
            // with a never-hydrating content area — empty right pane on all
            // panel pages, invisible to js.runtime_error reporting (that
            // reporter rides app.js too). This panel-scoped hook restores
            // exactly what the auto-injector used to emit on panel pages:
            // the stock livewire.min.js tag, which self-configures via its
            // data-csrf/data-module-url/data-update-uri attributes — the
            // window.livewireScriptConfig object is only needed by the
            // bundled-ESM flow, and scriptConfig() would consume
            // FrontendAssets' once-guard and suppress this very tag.
            // scripts() has its own once-guard; app pages are untouched
            // (the hook fires only in the panel layout).
            //
            // The styles half (2026-11-22, hours later): inject_assets=false
            // also killed Livewire's auto-injected STYLESHEET — the base
            // block whose wire:loading rules keep loading indicators
            // hidden until a request is actually in flight. The app layout
            // has them ported into app.css (a447e1c6); the panel layout
            // loads neither app.css nor Livewire's block, so every wire:
            // loading swirly on admin pages rendered permanently visible
            // (user-reported: indefinite spinners beside each control,
            // "like the logged-in layout had"). FrontendAssets::styles()
            // restores the stock block (loading states, x-cloak, progress
            // bar); its own once-guard makes double emission impossible.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => FrontendAssets::styles(),
            )
            ->renderHook(
                PanelsRenderHook::SCRIPTS_BEFORE,
                fn (): string => FrontendAssets::scripts(),
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => FrontendAssets::styles(),
            )
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
            ->authMiddleware([
                AuthenticateFilament::class,
            ]);
    }
}
