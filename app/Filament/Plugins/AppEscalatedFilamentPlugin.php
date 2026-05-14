<?php

namespace App\Filament\Plugins;

use App\Filament\Pages\Escalated\EscalatedEmailSettings;
use App\Filament\Pages\Escalated\EscalatedManagePlugins;
use App\Filament\Pages\Escalated\EscalatedReports;
use App\Filament\Pages\Escalated\EscalatedSettings;
use App\Filament\Pages\Escalated\EscalatedSsoSettings;
use Escalated\Filament\EscalatedFilamentPlugin;
use Escalated\Filament\Pages\Dashboard;
use Escalated\Filament\Livewire\SatisfactionRating;
use Escalated\Filament\Livewire\TicketConversation;
use Filament\Panel;

/**
 * Application-level Escalated plugin that extends the vendor plugin.
 *
 * Overrides page registration to use custom page classes with proper
 * visibility gating (canAccess/shouldRegisterNavigation) based on
 * Spatie roles via the escalated-agent/escalated-admin gates.
 *
 * Resources inherit the full vendor registration unchanged — visibility
 * is controlled via app-level Policies registered in AppServiceProvider.
 */
class AppEscalatedFilamentPlugin extends EscalatedFilamentPlugin
{
    public function register(Panel $panel): void
    {
        if (! config('escalated.ui.enabled', true)) {
            return;
        }

        $panel
            ->resources($this->resources)
            ->pages([
                // Dashboard: visible to ALL panel users (no gate override)
                Dashboard::class,
                // Reports: escalated-agent (Platform Admin + Service Admin)
                EscalatedReports::class,
                // Settings pages: escalated-admin (Platform Admin only)
                EscalatedSettings::class,
                EscalatedSsoSettings::class,
                EscalatedEmailSettings::class,
                EscalatedManagePlugins::class,
            ])
            ->livewireComponents([
                TicketConversation::class,
                SatisfactionRating::class,
            ]);
    }
}
