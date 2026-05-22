<?php

namespace App\Filament\Resources\TeamResource\Pages;

use App\Filament\Concerns\TransformsLocaleSwitchWithoutValidation;
use App\Filament\Resources\TeamResource;
use App\Services\SeoCacheService;
use Filament\Resources\Pages\EditRecord;
use LaraZeus\SpatieTranslatable\Actions\LocaleSwitcher;
use LaraZeus\SpatieTranslatable\Resources\Pages\EditRecord\Concerns\Translatable;

class EditTeam extends EditRecord
{
    use TransformsLocaleSwitchWithoutValidation, Translatable {
        TransformsLocaleSwitchWithoutValidation::updatedActiveLocale insteadof Translatable;
    }

    protected static string $resource = TeamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LocaleSwitcher::make(),
        ];
    }

    protected function afterSave(): void
    {
        app(SeoCacheService::class)->forgetByModel($this->record);
    }
}
