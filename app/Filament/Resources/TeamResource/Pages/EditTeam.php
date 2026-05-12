<?php

namespace App\Filament\Resources\TeamResource\Pages;

use App\Filament\Resources\TeamResource;
use App\Services\SeoCacheService;
use Filament\Resources\Pages\EditRecord;

class EditTeam extends EditRecord
{
    protected static string $resource = TeamResource::class;

    protected function afterSave(): void
    {
        app(SeoCacheService::class)->forgetByModel($this->record);
    }
}
