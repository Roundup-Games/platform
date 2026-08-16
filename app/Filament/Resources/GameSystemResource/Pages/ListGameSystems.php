<?php

namespace App\Filament\Resources\GameSystemResource\Pages;

use App\Filament\Resources\GameSystemResource;
use App\Jobs\SyncGameSystemsFromBgg;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Collection;

class ListGameSystems extends ListRecords
{
    protected static string $resource = GameSystemResource::class;

    public function getTableBulkActions(): array
    {
        return [
            DeleteBulkAction::make(),
            BulkAction::make('resyncBgg')
                ->label('Re-sync from BGG')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalHeading('Re-sync selected from BoardGameGeek')
                ->modalDescription('This will re-fetch data from BGG for all selected game systems that have a BGG ID. Continue?')
                ->action(function (Collection $records) {
                    /** @var array<int, int> $bggIds */
                    $bggIds = $records
                        ->filter(fn ($r) => $r->getAttribute('bgg_id') !== null)
                        ->pluck('bgg_id')
                        ->values()
                        ->toArray();

                    if (empty($bggIds)) {
                        Notification::make()
                            ->warning()
                            ->title('No BGG IDs')
                            ->body('None of the selected game systems have a BGG ID to sync.')
                            ->send();

                        return;
                    }

                    // Queue the sync — BGG rate-limit throttles and 202-retry
                    // sleeps make multi-batch syncs run for minutes; never block
                    // the admin request (see SyncGameSystemsFromBgg).
                    SyncGameSystemsFromBgg::dispatch($bggIds);

                    Notification::make()
                        ->success()
                        ->title('BGG sync queued')
                        ->body(count($bggIds).' game system(s) will re-sync from BGG in the background. Re-check "last synced" in a few minutes.')
                        ->send();
                }),
        ];
    }
}
