<?php

namespace App\Filament\Resources\GameResource\Pages;

use App\Filament\Resources\GameResource;
use App\Services\SeoCacheService;
use Filament\Resources\Pages\EditRecord;

class EditGame extends EditRecord
{
    protected static string $resource = GameResource::class;

    protected function afterSave(): void
    {
        app(SeoCacheService::class)->forgetByModel($this->record);
    }
}
