<?php

namespace App\Filament\Resources\GameResource\Pages;

use App\Enums\AttendanceResolutionMethod;
use App\Enums\GameType;
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

    /**
     * Inject the virtual game_system_id into the form data before fill.
     *
     * Filament fills the edit form from $record->attributesToArray(), which
     * excludes accessors not listed in $appends. game_system_id is a virtual
     * accessor (getGameSystemIdAttribute) backed by the gameSystems pivot, so
     * without this override the single-system picker renders empty on edit
     * even though the game has a system attached. The multi-select gameSystems
     * field hydrates via its relationship loader and needs no help here.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Game $record */
        $record = $this->getRecord();

        $data['game_system_id'] = $record->gameSystems->first()?->id;

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Game $record */
        $record = $this->getRecord();

        // Sync the single game_system_id picker to the gameSystems pivot.
        // The model's setGameSystemIdAttribute bridge captures the value, but
        // only the `creating` event syncs it (fired on create, not update).
        // For focused sessions edited here, sync manually so the pivot stays
        // correct. Only applies when the form carried game_system_id (focused
        // types); the Gathering multi-select is handled by the relationship
        // field automatically.
        $systemId = $this->data['game_system_id'] ?? null;
        if (is_string($systemId) && $systemId !== '' && $record->game_type !== GameType::Gathering) {
            $record->gameSystems()->sync([$systemId]);
        }

        app(SeoCacheService::class)->forgetByModel($record);
    }
}
