<?php

namespace App\Filament\Resources\EventResource\Pages;

use App\Filament\Resources\EventResource;
use App\Services\SeoCacheService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    protected function afterSave(): void
    {
        app(SeoCacheService::class)->forgetByModel($this->record);
    }
}
