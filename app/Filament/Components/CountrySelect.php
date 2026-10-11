<?php

namespace App\Filament\Components;

use App\Support\Country;
use Filament\Forms\Components\Select;
use Illuminate\Validation\Rule;

/**
 * The platform-standard country select (D173): searchable, localized
 * display names (symfony/intl), stores ISO 3166-1 alpha-3.
 *
 * Usage in a Resource form():  CountrySelect::make('country')
 *
 * Replaces free-text country inputs everywhere location data is
 * collected — the source of the mixed ISO-2/ISO-3 values the 190000
 * migration had to clean up. INV-14 pins TextInput::make('country')
 * out of app/Filament.
 */
class CountrySelect
{
    public static function make(string $field = 'country'): Select
    {
        return Select::make($field)
            ->label('Country')
            ->options(fn (): array => Country::options())
            ->searchable()
            ->rule(Rule::in(array_keys(Country::options('en'))))
            ->helperText('ISO country code (e.g. DEU). Displayed in the admin language.');
    }
}
