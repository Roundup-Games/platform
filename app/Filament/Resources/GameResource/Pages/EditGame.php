<?php

namespace App\Filament\Resources\GameResource\Pages;

use App\Enums\AttendanceResolutionMethod;
use App\Filament\Concerns\TransformsLocaleSwitchWithoutValidation;
use App\Filament\Resources\GameResource;
use App\Models\Game;
use App\Services\AttendanceResolutionService;
use App\Services\SeoCacheService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use LaraZeus\SpatieTranslatable\Actions\LocaleSwitcher;
use LaraZeus\SpatieTranslatable\Resources\Pages\EditRecord\Concerns\Translatable;

class EditGame extends EditRecord
{
    use TransformsLocaleSwitchWithoutValidation, Translatable {
        TransformsLocaleSwitchWithoutValidation::updatedActiveLocale insteadof Translatable;
    }

    protected static string $resource = GameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resolveAttendance')
                ->label('Resolve Attendance')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(function () {
                    /** @var Game $record */
                    $record = $this->getRecord();

                    return $record->status?->value === 'completed'
                        && $record->attendance_resolved_at === null;
                })
                ->requiresConfirmation()
                ->modalHeading('Resolve Attendance')
                ->modalDescription('Manually trigger attendance resolution for this game. This will resolve attendance for all participants based on filed reports.')
                ->action(function () {
                    /** @var Game $record */
                    $record = $this->getRecord();
                    /** @var AttendanceResolutionService $service */
                    $service = app(AttendanceResolutionService::class);
                    $service->resolveGameAttendance(
                        $record,
                        AttendanceResolutionMethod::Manual,
                    );

                    Notification::make()
                        ->title('Attendance resolved successfully')
                        ->success()
                        ->send();

                    $this->redirect($this->getUrl(['record' => $record]));
                }),
            LocaleSwitcher::make(),
            ...parent::getHeaderActions(),
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        app(SeoCacheService::class)->forgetByModel($this->getRecord());
    }
}
