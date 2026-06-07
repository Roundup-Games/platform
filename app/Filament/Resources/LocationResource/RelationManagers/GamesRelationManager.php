<?php

namespace App\Filament\Resources\LocationResource\RelationManagers;

use App\Enums\GameStatus;
use App\Enums\GameType;
use App\Enums\Visibility;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GamesRelationManager extends RelationManager
{
    protected static string $relationship = 'games';

    protected static ?string $title = 'Games';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->limit(30)
                    ->sortable(),
                TextColumn::make('owner.name')
                    ->label('Game Master')
                    ->searchable(),
                TextColumn::make('date_time')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('game_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (GameType $state): string => $state->label()),
                TextColumn::make('visibility')
                    ->badge()
                    ->formatStateUsing(fn (Visibility $state): string => $state->label()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (GameStatus $state): string => $state->label()),
            ])
            ->defaultSort('date_time', 'desc');
    }
}
