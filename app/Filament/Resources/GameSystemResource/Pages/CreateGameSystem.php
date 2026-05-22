<?php

namespace App\Filament\Resources\GameSystemResource\Pages;

use App\Filament\Resources\GameSystemResource;
use Filament\Resources\Pages\CreateRecord;
use LaraZeus\SpatieTranslatable\Resources\Pages\CreateRecord\Concerns\Translatable;

class CreateGameSystem extends CreateRecord
{
    use Translatable;

    protected static string $resource = GameSystemResource::class;
}
