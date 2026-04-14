<?php

namespace App\Filament\Resources\EventResource\Pages;

use App\Filament\Resources\EventResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    /**
     * Load existing translation values into the form so locale tabs are populated.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $translatableFields = $this->getRecord()->getTranslatableFields();

        foreach (EventResource::getTranslationLocales() as $locale) {
            foreach ($translatableFields as $field) {
                $key = "{$field}_{$locale}";
                $translation = $this->getRecord()->getTranslation($locale, $field);
                $data[$key] = $translation;
            }
        }

        return $data;
    }

    /**
     * Strip locale-suffixed virtual fields before the model is updated.
     * Translation persistence is handled in afterSave.
     */
    protected function mutateFormDataBeforeSave(array $data): array
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
     * Persist translations after the record has been saved.
     */
    protected function afterSave(): void
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

        parent::afterSave();
    }
}
