<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Resources\CityResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;
use LaraZeus\SpatieTranslatable\Resources\Pages\CreateRecord\Concerns\Translatable;

class CreateCity extends CreateRecord
{
    use Translatable;

    protected static string $resource = CityResource::class;

    /**
     * The hub slug is always derived from the selected city value — the
     * form field is read-only and this re-derivation makes it authoritative
     * on every write path, so a hand-typed slug can never reach the DB.
     *
     * Also runs per-locale inside the Translatable concern's write path,
     * where the locale payload holds only translatable keys — the derived
     * slug is filtered out by its Arr::only() there, so it is harmless.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $city = $data['city'] ?? null;
        $data['slug'] = Str::slug(is_string($city) ? $city : '');

        return $data;
    }
}
