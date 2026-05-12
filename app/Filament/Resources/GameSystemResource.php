<?php

namespace App\Filament\Resources;

use App\Filament\Components\SeoFields;
use App\Filament\Resources\GameSystemResource\Pages;
use App\Models\GameSystem;
use BackedEnum;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Repeater;
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
        return 'Game Systems';
    }

    public static function getNavigationIcon(): string | BackedEnum | null
    {
        return Heroicon::OutlinedCube;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // ── Core Details ────────────────────────────
                Section::make('Game System Details')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('slug')
                                    ->maxLength(255),
                                Select::make('type')
                                    ->label('Type')
                                    ->options([
                                        'boardgame' => 'Board Game',
                                        'boardgameexpansion' => 'Expansion',
                                        'ttrpg' => 'TTRPG',
                                    ])
                                    ->default('boardgame')
                                    ->required()
                                    ->live(),
                                TextInput::make('year_released')
                                    ->label('Year Released')
                                    ->numeric(),
                            ]),
                        Textarea::make('description')
                            ->rows(3)
                            ->maxLength(65535)
                            ->columnSpanFull(),
                    ]),

                // ── BGG Statistics (Board Games & Expansions) ─
                Section::make('BGG Data')
                    ->description('Data synced from BoardGameGeek.')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextInput::make('bgg_id')
                                    ->label('BGG ID')
                                    ->numeric()
                                    ->disabled(),
                                TextInput::make('bgg_type')
                                    ->label('BGG Type')
                                    ->disabled(),
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
                                TextInput::make('bgg_average_weight')
                                    ->label('Average Weight')
                                    ->numeric()
                                    ->disabled(),
                                TextInput::make('base_game_id')
                                    ->label('Base Game ID')
                                    ->numeric()
                                    ->disabled(),
                                TextInput::make('bgg_last_synced_at')
                                    ->label('Last BGG Sync')
                                    ->disabled(),
                            ]),
                    ])
                    ->visible(fn ($get) => $get('type') !== 'ttrpg'),

                // ── Game Properties (Board Games) ────────────
                Section::make('Game Properties')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextInput::make('min_players')
                                    ->numeric(),
                                TextInput::make('max_players')
                                    ->numeric(),
                                TextInput::make('optimal_players')
                                    ->numeric(),
                                TextInput::make('average_play_time')
                                    ->numeric(),
                                TextInput::make('age_rating')
                                    ->numeric(),
                                TextInput::make('complexity_rating')
                                    ->numeric(),
                            ]),
                    ])
                    ->visible(fn ($get) => $get('type') !== 'ttrpg'),

                // ── TTRPG-Specific Fields ────────────────────
                Section::make('TTRPG Details')
                    ->description('Fields specific to tabletop RPG systems.')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('creator')
                                    ->label('Creator')
                                    ->maxLength(255),
                                TextInput::make('player_range')
                                    ->label('Player Range')
                                    ->placeholder('e.g. 3-6 Players')
                                    ->maxLength(255),
                            ]),
                        Grid::make(2)
                            ->schema([
                                TextInput::make('source')
                                    ->label('Source')
                                    ->disabled(),
                                TextInput::make('source_slug')
                                    ->label('Source Slug')
                                    ->disabled(),
                            ]),
                        Grid::make(2)
                            ->schema([
                                TextInput::make('sp_rating')
                                    ->label('SP Rating')
                                    ->numeric(),
                                TextInput::make('sp_review_count')
                                    ->label('SP Review Count')
                                    ->numeric(),
                            ]),
                    ])
                    ->visible(fn ($get) => $get('type') === 'ttrpg'),

                // ── TTRPG FAQ ───────────────────────────────
                Section::make('FAQ Content')
                    ->description('Frequently asked questions and answers for this TTRPG system.')
                    ->schema([
                        Repeater::make('faq_content')
                            ->label('FAQ Entries')
                            ->schema([
                                TextInput::make('question')
                                    ->required()
                                    ->maxLength(500),
                                Textarea::make('answer')
                                    ->required()
                                    ->rows(2)
                                    ->maxLength(2000),
                            ])
                            ->collapsible()
                            ->reorderable()
                            ->addActionLabel('Add FAQ entry')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($get) => $get('type') === 'ttrpg'),

                // ── TTRPG External Links ────────────────────
                Section::make('External Links')
                    ->description('Related external resources and references.')
                    ->schema([
                        Repeater::make('external_links')
                            ->label('Links')
                            ->schema([
                                TextInput::make('title')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('url')
                                    ->required()
                                    ->url()
                                    ->maxLength(500),
                                TextInput::make('type')
                                    ->label('Link Type')
                                    ->placeholder('e.g. official, review, store')
                                    ->maxLength(50),
                            ])
                            ->collapsible()
                            ->reorderable()
                            ->addActionLabel('Add link')
                            ->grid(2)
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($get) => $get('type') === 'ttrpg'),

                // ── TTRPG Showcases ─────────────────────────
                Section::make('Showcases')
                    ->description('Featured images and descriptions highlighting the system.')
                    ->schema([
                        Repeater::make('showcases')
                            ->label('Showcase Items')
                            ->schema([
                                TextInput::make('title')
                                    ->required()
                                    ->maxLength(255),
                                Textarea::make('description')
                                    ->rows(2)
                                    ->maxLength(1000),
                                TextInput::make('image')
                                    ->label('Image URL')
                                    ->url()
                                    ->maxLength(500),
                            ])
                            ->collapsible()
                            ->reorderable()
                            ->addActionLabel('Add showcase item')
                            ->grid(2)
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($get) => $get('type') === 'ttrpg'),

                // ── TTRPG Instructions ──────────────────────
                Section::make('Instructions')
                    ->description('How-to-play instructions and tutorial videos.')
                    ->schema([
                        Repeater::make('instructions')
                            ->label('Instruction Steps')
                            ->schema([
                                TextInput::make('title')
                                    ->required()
                                    ->maxLength(255),
                                Textarea::make('description')
                                    ->rows(2)
                                    ->maxLength(2000),
                                TextInput::make('video_url')
                                    ->label('Video URL')
                                    ->url()
                                    ->maxLength(500),
                            ])
                            ->collapsible()
                            ->reorderable()
                            ->addActionLabel('Add instruction step')
                            ->grid(2)
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($get) => $get('type') === 'ttrpg'),

                // ── Taxonomy ─────────────────────────────────
                Section::make('Taxonomy')
                    ->description('Categories, mechanics, and other classifications.')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('categories')
                                    ->label('Categories')
                                    ->relationship('categories', 'name')
                                    ->multiple()
                                    ->preload()
                                    ->createOptionForm([
                                        TextInput::make('name')->required()->maxLength(255),
                                        Textarea::make('description')->maxLength(65535),
                                    ]),
                                Select::make('mechanics')
                                    ->label('Mechanics')
                                    ->relationship('mechanics', 'name')
                                    ->multiple()
                                    ->preload()
                                    ->createOptionForm([
                                        TextInput::make('name')->required()->maxLength(255),
                                        Textarea::make('description')->maxLength(65535),
                                    ]),
                                Select::make('families')
                                    ->label('Families')
                                    ->relationship('families', 'name')
                                    ->multiple()
                                    ->preload(),
                                Select::make('designers')
                                    ->label('Designers')
                                    ->relationship('designers', 'name')
                                    ->multiple()
                                    ->preload(),
                                Select::make('publishers')
                                    ->label('Publishers')
                                    ->relationship('publishers', 'name')
                                    ->multiple()
                                    ->preload(),
                            ]),
                    ]),

                // ── SEO Overrides ─────────────────────────────
                SeoFields::make(),
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
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'boardgame' => 'success',
                        'boardgameexpansion' => 'info',
                        'ttrpg' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'boardgame' => 'Board Game',
                        'boardgameexpansion' => 'Expansion',
                        'ttrpg' => 'TTRPG',
                        default => $state,
                    })
                    ->sortable(),
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
                    ->formatStateUsing(fn ($record) => $record->player_range ?? "{$record->min_players}-{$record->max_players}")
                    ->toggleable(),
                TextColumn::make('bgg_average_rating')
                    ->label('Rating')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('categories_count')
                    ->label('Categories')
                    ->counts('categories')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        'boardgame' => 'Board Game',
                        'boardgameexpansion' => 'Expansion',
                        'ttrpg' => 'TTRPG',
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
