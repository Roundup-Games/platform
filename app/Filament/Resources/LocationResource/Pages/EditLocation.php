<?php

namespace App\Filament\Resources\LocationResource\Pages;

use App\Filament\Resources\LocationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLocation extends EditRecord
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Hydrate the Operational Parameters virtual fields (overlap_guidance,
     * fee_display, house_rules) from the persisted venue_metadata envelope.
     *
     * These keys are not real columns; they are flattened out of the
     * venue_metadata JSON for editing and repacked in
     * {@see mutateFormDataBeforeSave()}. The disabled raw venue_metadata
     * Textarea populates itself via its formatStateUsing callback.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $metadata = $this->getRecord()->venue_metadata ?? [];

        $data['overlap_guidance'] = $metadata['overlap_guidance'] ?? null;
        $data['fee_display'] = $metadata['fee_display'] ?? null;
        $data['house_rules'] = $metadata['house_rules'] ?? null;

        return $data;
    }

    /**
     * Merge the three operational parameter fields into venue_metadata,
     * preserving any pre-existing sub-keys (approved_from_ticket,
     * proposed_by_user_id, geocoded_display_name, etc.). Empty strings are
     * normalized to null so the public page hide-when-empty check stays simple.
     *
     * Delegates to {@see LocationResource::packOperationalParameters()} so the
     * pack/unpack logic lives in one place shared with CreateLocation.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Read the existing venue_metadata envelope, falling back to [] when
        // null. The ?? operator also suppresses PHPStan's magic-property
        // access warning on Model::__get (matches the pre-refactor pattern).
        $existing = $this->getRecord()->venue_metadata ?? [];

        return LocationResource::packOperationalParameters(
            $data,
            is_array($existing) ? $existing : [],
        );
    }
}
