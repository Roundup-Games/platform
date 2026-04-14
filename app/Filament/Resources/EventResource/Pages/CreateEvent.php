<?php

namespace App\Filament\Resources\EventResource\Pages;

use App\Filament\Resources\EventResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;

    /**
     * Strip locale-suffixed virtual fields before the model is created.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $translatableFields = (new ($this->getResource()::getModel()))->getTranslatableFields();

        foreach (EventResource::getTranslationLocales() as $locale) {
            foreach ($translatableFields as $field) {
                unset($data["{$field}_{$locale}"]);
            }
        }

        return $data;
    }

    /**
     * Persist translations after the record has been created.
     */
    protected function afterCreate(): void
    {
        $data = $this->form->getRawState();
        $translatableFields = $this->getRecord()->getTranslatableFields();

        foreach (EventResource::getTranslationLocales() as $locale) {
            foreach ($translatableFields as $field) {
                $key = "{$field}_{$locale}";
                if (isset($data[$key]) && $data[$key] !== '' && $data[$key] !== null) {
                    $this->getRecord()->setTranslation($locale, $field, $data[$key]);
                }
            }
        }

        parent::afterCreate();
    }
}
