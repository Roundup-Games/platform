<?php

namespace App\Filament\Resources\EventResource\Pages;

use App\Enums\EventStatus;
use App\Filament\Concerns\TransformsLocaleSwitchWithoutValidation;
use App\Filament\Resources\EventResource;
use App\Services\EventLifecycleService;
use App\Services\SeoCacheService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LaraZeus\SpatieTranslatable\Actions\LocaleSwitcher;
use LaraZeus\SpatieTranslatable\Resources\Pages\EditRecord\Concerns\Translatable;

class EditEvent extends EditRecord
{
    use TransformsLocaleSwitchWithoutValidation, Translatable {
        TransformsLocaleSwitchWithoutValidation::updatedActiveLocale insteadof Translatable;
    }

    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LocaleSwitcher::make(),
            ...parent::getHeaderActions(),
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        app(SeoCacheService::class)->forgetByModel($this->getRecord());
    }

    /**
     * Route cancellations through EventLifecycleService so the admin edit
     * form and the organizer-facing ManageEvent flows share one lifecycle
     * path: the service owns the status write and notifies every active
     * registrant exactly once per cancellation transition (M063/S03).
     *
     * The status is stripped from the form payload on that path (the
     * service performs the write after the remaining fields save); a
     * re-save that keeps an already-cancelled event cancelled is a plain
     * update with no second dispatch.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $cancelling = ($data['status'] ?? null) === EventStatus::Cancelled->value
            && $record->status !== EventStatus::Cancelled;

        if ($cancelling) {
            unset($data['status']);
        }

        $record = parent::handleRecordUpdate($record, $data);

        if ($cancelling) {
            app(EventLifecycleService::class)->cancel($record);
        }

        return $record;
    }
}
