<?php

namespace App\Filament\Resources\CityResource\RelationManagers;

use App\Filament\Resources\LocationResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The evidence behind a hub: every location linked to this registry
 * cluster. Read-focused by design — a mis-clustered venue is fixed at
 * the location (re-geocode), never by editing the hub, and the registry
 * relinks automatically on the next location save.
 */
class LocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'locations';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('city')
                    ->label('City (geocoder provenance)')
                    ->sortable(),
                TextColumn::make('country')
                    ->sortable(),
                TextColumn::make('venue_type')
                    ->badge(),
                IconColumn::make('is_verified')
                    ->label('Verified')
                    ->boolean(),
                TextColumn::make('average_rating')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record): string => LocationResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
