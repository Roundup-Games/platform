<?php

namespace App\Filament\Resources\GameSystemResource\Pages;

use App\Exceptions\BggApiException;
use App\Filament\Concerns\TransformsLocaleSwitchWithoutValidation;
use App\Filament\Resources\GameSystemResource;
use App\Services\BggSyncService;
use App\Services\SeoCacheService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use LaraZeus\SpatieTranslatable\Actions\LocaleSwitcher;
use LaraZeus\SpatieTranslatable\Resources\Pages\EditRecord\Concerns\Translatable;

class EditGameSystem extends EditRecord
{
    use TransformsLocaleSwitchWithoutValidation, Translatable {
        TransformsLocaleSwitchWithoutValidation::updatedActiveLocale insteadof Translatable;
    }

    protected static string $resource = GameSystemResource::class;

    protected function afterSave(): void
    {
        app(SeoCacheService::class)->forgetByModel($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            LocaleSwitcher::make(),
            DeleteAction::make(),
            Action::make('resyncBgg')
                ->label('Re-sync from BGG')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->modalHeading('Re-sync from BoardGameGeek')
                ->modalDescription('This will re-fetch data from BGG for this game system. Continue?')
                ->action(function ($record) {
                    if (! $record->bgg_id) {
                        Notification::make()
                            ->warning()
                            ->title('No BGG ID')
                            ->body('This game system has no BGG ID to sync.')
                            ->send();

                        return;
                    }

                    try {
                        app(BggSyncService::class)->syncGameSystems([$record->bgg_id]);

                        Notification::make()
                            ->success()
                            ->title('Sync complete')
                            ->body('Game system re-synced from BGG.')
                            ->send();

                        $this->refreshFormData(['bgg_last_synced_at']);
                    } catch (BggApiException $e) {
                        Notification::make()
                            ->danger()
                            ->title('Sync failed')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),
        ];
    }
}
