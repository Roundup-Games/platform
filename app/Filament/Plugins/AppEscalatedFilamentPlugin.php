<?php

namespace App\Filament\Plugins;

use App\Filament\Pages\Escalated\EscalatedEmailSettings;
use App\Filament\Pages\Escalated\EscalatedManagePlugins;
use App\Filament\Pages\Escalated\EscalatedReports;
use App\Filament\Pages\Escalated\EscalatedSettings;
use App\Filament\Pages\Escalated\EscalatedSsoSettings;
use App\Filament\Resources\TicketResource;
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
 * Resources: the vendor TicketResource is replaced with an app-level
 * TicketResource that uses a custom ViewTicket page providing game-system
 * BGG sync actions. All other vendor resources are inherited unchanged.
 */
class AppEscalatedFilamentPlugin extends EscalatedFilamentPlugin
{
    public function register(Panel $panel): void
    {
        if (! config('escalated.ui.enabled', true)) {
            return;
        }

        // Build resource list: replace vendor resources with app-level overrides
        $resources = collect($this->resources)
            ->map(function (string $resourceClass) {
                if ($resourceClass === \Escalated\Filament\Resources\TicketResource::class) {
                    return TicketResource::class;
                }

                if ($resourceClass === \Escalated\Filament\Resources\DepartmentResource::class) {
                    return \App\Filament\Resources\DepartmentResource::class;
                }

                return $resourceClass;
            })
            ->values()
            ->all();

        $panel
            ->resources($resources)
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
