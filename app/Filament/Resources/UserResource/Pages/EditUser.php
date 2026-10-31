<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\SeoCacheService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function afterSave(): void
    {
        app(SeoCacheService::class)->forgetByModel($this->getRecord());
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var User $record */
        $record = $this->getRecord();

        // Admin timezone semantics (R089): a timezone CHANGED by an admin
        // save is a manual choice — it pins the zone against every device
        // capture path, and clearing it returns the user to device capture.
        // Change-detection only: an unrelated save (role, name, …) never
        // flips the pin, because the untouched select round-trips the
        // stored value (alias-safe options, UserResource::form).
        if (array_key_exists('timezone', $data)) {
            $newTimezone = $data['timezone'];
            $newTimezone = $newTimezone === '' ? null : $newTimezone;
            $data['timezone'] = $newTimezone;

            if ($record->timezone !== $newTimezone) {
                $data['timezone_manually_set'] = $newTimezone !== null;
            }
        }

        // When is_disabled is toggled via the form, sync disabled_at
        $wasDisabled = $record->is_disabled;
        $nowDisabled = (bool) ($data['is_disabled'] ?? false);

        if ($nowDisabled && ! $wasDisabled) {
            $data['disabled_at'] = $data['disabled_at'] ?? now();
            Log::warning('User account disabled via admin form', [
                'user_id' => $record->id,
                'disabled_by' => auth()->id(),
            ]);
        } elseif (! $nowDisabled && $wasDisabled) {
            $data['disabled_at'] = null;
            Log::info('User account re-enabled via admin form', [
                'user_id' => $record->id,
                'enabled_by' => auth()->id(),
            ]);
        }

        return $data;
    }
}
