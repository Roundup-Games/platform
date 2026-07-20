<?php

namespace App\Filament\Resources\LocationResource\Pages;

use App\Filament\Resources\LocationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLocation extends CreateRecord
{
    protected static string $resource = LocationResource::class;

    /**
     * Pack the three Operational Parameters virtual fields into a fresh
     * venue_metadata envelope before the Location is persisted. On create
     * there is no existing record, so seed from an empty array.
     *
     * Delegates to {@see LocationResource::packOperationalParameters()} so
     * the pack logic lives in one place shared with EditLocation.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return LocationResource::packOperationalParameters($data, []);
    }
}
