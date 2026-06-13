<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\SeoCacheService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function afterSave(): void
    {
        app(SeoCacheService::class)->forgetByModel($this->record);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // When is_disabled is toggled via the form, sync disabled_at
        $wasDisabled = $this->record->is_disabled;
        $nowDisabled = (bool) ($data['is_disabled'] ?? false);

        if ($nowDisabled && ! $wasDisabled) {
            $data['disabled_at'] = $data['disabled_at'] ?? now();
            Log::warning('User account disabled via admin form', [
                'user_id' => $this->record->id,
                'disabled_by' => auth()->id(),
            ]);
        } elseif (! $nowDisabled && $wasDisabled) {
            $data['disabled_at'] = null;
            Log::info('User account re-enabled via admin form', [
                'user_id' => $this->record->id,
                'enabled_by' => auth()->id(),
            ]);
        }

        return $data;
    }
}
