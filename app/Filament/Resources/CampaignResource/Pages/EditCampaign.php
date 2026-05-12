<?php

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Filament\Resources\CampaignResource;
use App\Services\SeoCacheService;
use Filament\Resources\Pages\EditRecord;

class EditCampaign extends EditRecord
{
    protected static string $resource = CampaignResource::class;

    protected function afterSave(): void
    {
        app(SeoCacheService::class)->forgetByModel($this->record);
    }
}
