<?php

namespace App\Filament\Resources\LocationResource\RelationManagers;

use App\Enums\EventStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Events';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->limit(30)
                    ->sortable(),
                TextColumn::make('organizer.name')
                    ->label('Organizer')
                    ->searchable(),
                TextColumn::make('start_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (EventStatus $state): string => Str::headline($state->value)),
                IconColumn::make('is_public')
                    ->label('Public')
                    ->boolean(),
            ])
            ->defaultSort('start_date', 'desc');
    }
}
