<?php

namespace App\Filament\Resources\GameSystemResource\Pages;

use App\Exceptions\BggApiException;
use App\Filament\Resources\GameSystemResource;
use App\Services\BggSyncService;
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
                    $bggIds = $records->filter(fn ($r) => $r->bgg_id)->pluck('bgg_id')->values()->toArray();

                    if (empty($bggIds)) {
                        Notification::make()
                            ->warning()
                            ->title('No BGG IDs')
                            ->body('None of the selected game systems have a BGG ID to sync.')
                            ->send();

                        return;
                    }

                    try {
                        $result = app(BggSyncService::class)->syncGameSystems($bggIds);

                        Notification::make()
                            ->success()
                            ->title('Bulk sync complete')
                            ->body("Synced {$result['synced']} game system(s), {$result['failed']} failed.")
                            ->send();
                    } catch (BggApiException $e) {
                        Notification::make()
                            ->danger()
                            ->title('Bulk sync failed')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),
        ];
    }
}
