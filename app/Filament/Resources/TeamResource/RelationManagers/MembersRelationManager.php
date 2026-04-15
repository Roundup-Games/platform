<?php

namespace App\Filament\Resources\TeamResource\RelationManagers;

use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Members';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)
                    ->schema([
                        Select::make('user_id')
                            ->label('User')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->required(),
                        Select::make('role')
                            ->options([
                                'captain' => 'Captain',
                                'coach' => 'Coach',
                                'player' => 'Player',
                                'substitute' => 'Substitute',
                            ])
                            ->required()
                            ->default('player'),
                        Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'inactive' => 'Inactive',
                                'invited' => 'Invited',
                                'suspended' => 'Suspended',
                            ])
                            ->required()
                            ->default('active'),
                        TextInput::make('jersey_number')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(999),
                        TextInput::make('position')
                            ->maxLength(100),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'captain' => 'warning',
                        'coach' => 'info',
                        'player' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'invited' => 'info',
                        'suspended' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('jersey_number')
                    ->label('#')
                    ->toggleable(),
                TextColumn::make('position')
                    ->toggleable(),
                TextColumn::make('joined_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
