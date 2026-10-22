<?php

namespace App\Filament\Resources\BadgeDefinitionResource\Pages;

use App\Filament\Resources\BadgeDefinitionResource;
use App\Jobs\RunBadgeSweep;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListBadgeDefinitions extends ListRecords
{
    protected static string $resource = BadgeDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Same orchestrator as the nightly 06:30 run (registry sync
            // + every evaluator), idempotent end-to-end because every
            // write flows through the audited BadgeService pipeline.
            // Dispatched, never inline: the sweep chunks the whole users
            // table per evaluator — minutes of work that must live on
            // Horizon, not inside an HTTP request (concurrent runs
            // converge idempotently, overlap with the nightly run is
            // safe by the same contract).
            Action::make('runSweepNow')
                ->label('Run sweep now')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Run the badge sweep now?')
                ->modalDescription('Queues the same orchestrator as the nightly badges:sweep: registry sync, then every evaluator. Safe to re-run — awards are idempotent and failure-isolated. Completion and per-evaluator results land in the logs (badges.sweep.*) and Horizon.')
                // Sweep dispatch is an admin-only capability, not an
                // incidental of panel admission (the comp-grant idiom):
                // the action queues a full users-table pass per
                // evaluator and must not be repeatable by every role
                // that can reach the badge list.
                ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false)
                ->action(function (): void {
                    // Server-side twin of visible() — Livewire can invoke
                    // an action without re-running visibility (the
                    // comp-grant idiom).
                    $actor = auth()->user();

                    if (! $actor instanceof User || ! $actor->isAdmin()) {
                        return;
                    }

                    RunBadgeSweep::dispatch();

                    Notification::make()
                        ->info()
                        ->title('Badge sweep queued')
                        ->body('The full sweep is running on the queue. Watch Horizon or the badges.sweep.* logs for the outcome.')
                        ->send();
                }),
        ];
    }
}
