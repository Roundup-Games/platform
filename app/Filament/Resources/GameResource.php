<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GameResource\Pages;
use App\Filament\Resources\GameResource\RelationManagers\ParticipantsRelationManager;
use App\Models\Game;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GameResource extends Resource
{
    protected static ?string $model = Game::class;

    protected static ?int $navigationSort = 3;

    public static function getNavigationIcon(): string | BackedEnum | null
    {
        return Heroicon::OutlinedPuzzlePiece;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Game Details')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Select::make('owner_id')
                                    ->label('Game Master')
                                    ->relationship('owner', 'name')
                                    ->searchable()
                                    ->required(),
                                Select::make('game_system_id')
                                    ->label('Game System')
                                    ->relationship('gameSystem', 'name')
                                    ->searchable()
                                    ->preload(),
                                Select::make('campaign_id')
                                    ->label('Campaign')
                                    ->relationship('campaign', 'name')
                                    ->searchable()
                                    ->preload(),
                                DateTimePicker::make('date_time')
                                    ->label('Date & Time')
                                    ->required(),
                                TextInput::make('expected_duration')
                                    ->label('Duration (hours)')
                                    ->numeric()
                                    ->step(0.5),
                            ]),
                    ]),

                Section::make('Details')
                    ->schema([
                        Textarea::make('description')
                            ->rows(3)
                            ->maxLength(2000),
                        Grid::make(3)
                            ->schema([
                                TextInput::make('price')
                                    ->numeric()
                                    ->prefix('$')
                                    ->step(0.01)
                                    ->default(0),
                                Select::make('language')
                                    ->options([
                                        'en' => 'English',
                                        'sv' => 'Swedish',
                                        'no' => 'Norwegian',
                                        'da' => 'Danish',
                                        'fi' => 'Finnish',
                                    ])
                                    ->default('en'),
                            ]),
                    ]),

                Section::make('Visibility & Status')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('visibility')
                                    ->options([
                                        'public' => 'Public',
                                        'protected' => 'Protected',
                                        'private' => 'Private',
                                    ])
                                    ->default('public')
                                    ->required(),
                                Select::make('status')
                                    ->options([
                                        'draft' => 'Draft',
                                        'scheduled' => 'Scheduled',
                                        'in_progress' => 'In Progress',
                                        'completed' => 'Completed',
                                        'cancelled' => 'Cancelled',
                                    ])
                                    ->default('draft')
                                    ->required(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('owner.name')
                    ->label('Game Master')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('gameSystem.name')
                    ->label('System')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('date_time')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('visibility')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'public' => 'success',
                        'protected' => 'warning',
                        'private' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'scheduled' => 'info',
                        'in_progress' => 'warning',
                        'completed' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('participants_count')
                    ->label('Players')
                    ->counts('participants')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ])
            ->toolbarActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ParticipantsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGames::route('/'),
            'create' => Pages\CreateGame::route('/create'),
            'edit' => Pages\EditGame::route('/{record}/edit'),
        ];
    }
}
