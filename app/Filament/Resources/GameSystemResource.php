<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GameSystemResource\Pages;
use App\Models\GameSystem;
use BackedEnum;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GameSystemResource extends Resource
{
    protected static ?string $model = GameSystem::class;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): string | BackedEnum | null
    {
        return 'BGG Data';
    }

    public static function getNavigationIcon(): string | BackedEnum | null
    {
        return Heroicon::OutlinedCube;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Game System Details')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('slug')
                                    ->maxLength(255),
                                TextInput::make('bgg_id')
                                    ->label('BGG ID')
                                    ->numeric()
                                    ->disabled(),
                                TextInput::make('bgg_type')
                                    ->label('BGG Type')
                                    ->disabled(),
                            ]),
                        Textarea::make('description')
                            ->rows(3)
                            ->maxLength(65535)
                            ->columnSpanFull(),
                    ]),
                Section::make('BGG Statistics')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextInput::make('bgg_rank')
                                    ->label('Rank')
                                    ->numeric()
                                    ->disabled(),
                                TextInput::make('bgg_average_rating')
                                    ->label('Average Rating')
                                    ->numeric()
                                    ->disabled(),
                                TextInput::make('bgg_users_rated')
                                    ->label('Users Rated')
                                    ->numeric()
                                    ->disabled(),
                            ]),
                    ]),
                Section::make('Taxonomy')
                    ->description('Categories, mechanics, and other classifications synced from BGG.')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('categories')
                                    ->label('Categories')
                                    ->relationship('categories', 'name')
                                    ->multiple()
                                    ->disabled()
                                    ->preload(),
                                Select::make('mechanics')
                                    ->label('Mechanics')
                                    ->relationship('mechanics', 'name')
                                    ->multiple()
                                    ->disabled()
                                    ->preload(),
                                Select::make('families')
                                    ->label('Families')
                                    ->relationship('families', 'name')
                                    ->multiple()
                                    ->disabled()
                                    ->preload(),
                                Select::make('designers')
                                    ->label('Designers')
                                    ->relationship('designers', 'name')
                                    ->multiple()
                                    ->disabled()
                                    ->preload(),
                                Select::make('publishers')
                                    ->label('Publishers')
                                    ->relationship('publishers', 'name')
                                    ->multiple()
                                    ->disabled()
                                    ->preload(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('thumbnail_url')
                    ->label('Cover')
                    ->circular()
                    ->size(40),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('bgg_rank')
                    ->label('Rank')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('year_released')
                    ->label('Year')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('min_players')
                    ->label('Players')
                    ->formatStateUsing(fn ($record) => "{$record->min_players}-{$record->max_players}")
                    ->toggleable(),
                TextColumn::make('bgg_average_rating')
                    ->label('Rating')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('bgg_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'boardgame' => 'success',
                        'boardgameexpansion' => 'info',
                        default => 'gray',
                    })
                    ->toggleable(),
                TextColumn::make('categories_count')
                    ->label('Categories')
                    ->counts('categories')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('bgg_type')
                    ->options([
                        'boardgame' => 'Board Game',
                        'boardgameexpansion' => 'Expansion',
                    ]),
                SelectFilter::make('categories')
                    ->relationship('categories', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('bgg_rank', 'asc')
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ])
            ->toolbarActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGameSystems::route('/'),
            'create' => Pages\CreateGameSystem::route('/create'),
            'edit' => Pages\EditGameSystem::route('/{record}/edit'),
        ];
    }
}
