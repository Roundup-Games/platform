<?php

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Filament\Concerns\BindsAdminEditToAgreementLock;
use App\Filament\Concerns\TransformsLocaleSwitchWithoutValidation;
use App\Filament\Resources\CampaignResource;
use App\Models\Campaign;
use App\Services\SeoCacheService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use LaraZeus\SpatieTranslatable\Actions\LocaleSwitcher;
use LaraZeus\SpatieTranslatable\Resources\Pages\EditRecord\Concerns\Translatable;

class EditCampaign extends EditRecord
{
    use BindsAdminEditToAgreementLock;
    use TransformsLocaleSwitchWithoutValidation, Translatable {
        TransformsLocaleSwitchWithoutValidation::updatedActiveLocale insteadof Translatable;
    }

    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LocaleSwitcher::make(),
            ...parent::getHeaderActions(),
            DeleteAction::make(),
        ];
    }

    /**
     * D316: staff are bound by the agreement lock on the identity field — the
     * offered game systems — and free to correct everything else. Runs before
     * anything is written, so a refused save changes nothing.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->refuseChangedGameSystems($this->rawFormState());

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Campaign $record */
        $record = $this->getRecord();

        app(SeoCacheService::class)->forgetByModel($record);

        // D316: logged with the admin as actor and announced to the approved
        // participants, exactly like the owner path. Nothing when nothing changed.
        $this->announceAdminEdit($record);
    }
}
