<?php

namespace App\Filament\Resources\GameResource\Pages;

use App\Enums\AttendanceResolutionMethod;
use App\Enums\CalendarSyncOperation;
use App\Enums\GameType;
use App\Filament\Concerns\BindsAdminEditToAgreementLock;
use App\Filament\Concerns\TransformsLocaleSwitchWithoutValidation;
use App\Filament\Resources\GameResource;
use App\Models\Event;
use App\Models\Game;
use App\Services\AttendanceResolutionService;
use App\Services\Calendar\CalendarSyncDispatcher;
use App\Services\SeoCacheService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use LaraZeus\SpatieTranslatable\Actions\LocaleSwitcher;
use LaraZeus\SpatieTranslatable\Resources\Pages\EditRecord\Concerns\Translatable;

class EditGame extends EditRecord
{
    use BindsAdminEditToAgreementLock;
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

    /**
     * D316: staff are bound by the agreement lock on the IDENTITY fields —
     * the game type and the offered game system(s) — and free to correct
     * everything else. Runs before the model and its pivot are written, so a
     * refused save changes nothing.
     *
     * Values are read from the raw form state, not $data: the single-system
     * picker and the type are ordinary dehydrated fields, but the Gathering
     * multi-select never reaches $data (see BindsAdminEditToAgreementLock), and
     * the translatable resource replays this hook per locale with only the
     * translatable keys in $data.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Game $record */
        $record = $this->getRecord();

        if ($record->isAgreementLocked()) {
            $rawState = $this->rawFormState();

            $submittedType = $rawState['game_type'] ?? null;

            if (is_string($submittedType) && $submittedType !== $record->game_type?->value) {
                throw $this->agreementLockedException($record, 'data.game_type', ['game_type']);
            }

            $this->refuseChangedGameSystems($rawState);

            // Single-system picker (focused game types; hidden for Gatherings).
            $submittedSystemId = $rawState['game_system_id'] ?? null;

            if (is_string($submittedSystemId) && $submittedSystemId !== ''
                && $submittedSystemId !== $record->gameSystems->first()?->id) {
                throw $this->agreementLockedException($record, 'data.game_system_id', ['game_system_id']);
            }
        }

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

            // M063/S06/T02: pivot sync fires no model events — flush the
            // umbrella's derived offered-systems cache when the edited game
            // is a hosted table, mirroring the CreateGame host-a-table flush.
            if ($record->event_id !== null) {
                Event::flushOfferedSystemsCacheFor($record->event_id);
            }
        }

        app(SeoCacheService::class)->forgetByModel($record);

        // Managed-sync trigger (M083/S03-T03 parity with GamesPage's host
        // edit path): an admin content edit re-upserts the game on every
        // connected calendar that carries it. The reconcile sweep never
        // re-patches content, so this seam is the only admin-path
        // propagation for date/venue/name changes.
        CalendarSyncDispatcher::dispatchToRoster($record, CalendarSyncOperation::Upsert);

        // D316: the admin edit is logged with the admin as actor and announced
        // to the approved participants, exactly like the owner path. Filament
        // has already written the row here — no second write, only the
        // announcement, and nothing at all when nothing changed.
        $this->announceAdminEdit($record);
    }
}
