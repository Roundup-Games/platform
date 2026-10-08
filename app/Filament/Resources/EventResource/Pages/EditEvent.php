<?php

namespace App\Filament\Resources\EventResource\Pages;

use App\Enums\EventStatus;
use App\Filament\Concerns\TransformsLocaleSwitchWithoutValidation;
use App\Filament\Resources\EventResource;
use App\Models\Event;
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
        Translatable::mountTranslatable as mountTranslatableFromPlugin;
    }

    protected static string $resource = EventResource::class;

    /**
     * Livewire runs trait mount hooks AFTER the class mount(), so the
     * plugin's mountTranslatable unconditionally resets activeLocale to the
     * resource-default locale — after fillForm already resolved the
     * record-aware default (HasTranslatableFormWithExistingRecordData) via
     * `??=`. That stomp mislabels the form's locale: content filled from a
     * de-only record would render under "English" and edits would be
     * written to the wrong translation. fillForm always seeds the property,
     * so only fall back to the plugin's seeding when it somehow stayed
     * blank.
     */
    public function mountTranslatable(): void
    {
        if (blank($this->activeLocale)) {
            $this->mountTranslatableFromPlugin();
        }
    }

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
        // This resource only edits events; the narrowing keeps the
        // lifecycle write type-safe under strict analysis without
        // changing the parent contract.
        if (! $record instanceof Event) {
            return parent::handleRecordUpdate($record, $data);
        }

        $cancelling = ($data['status'] ?? null) === EventStatus::Cancelled->value
            && $record->status !== EventStatus::Cancelled;

        if ($cancelling) {
            unset($data['status']);
        }

        // parent saves and returns the same instance; keeping our narrowed
        // $record (Event) lets the lifecycle call below stay type-safe.
        parent::handleRecordUpdate($record, $data);

        if ($cancelling) {
            app(EventLifecycleService::class)->cancel($record);
        }

        return $record;
    }
}
