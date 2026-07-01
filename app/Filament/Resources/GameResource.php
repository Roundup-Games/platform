<?php

namespace App\Filament\Resources;

use App\Enums\AttendanceResolutionMethod;
use App\Enums\ContentLanguage;
use App\Enums\GameStatus;
use App\Enums\GameType;
use App\Enums\Visibility;
use App\Filament\Components\SeoFields;
use App\Filament\Resources\GameResource\Pages;
use App\Filament\Resources\GameResource\RelationManagers\AttendanceReportsRelationManager;
use App\Filament\Resources\GameResource\RelationManagers\ParticipantsRelationManager;
use App\Models\Game;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LaraZeus\SpatieTranslatable\Resources\Concerns\Translatable;

class GameResource extends Resource
{
    use Translatable;

    protected static ?string $model = Game::class;

    protected static ?int $navigationSort = 3;

    public static function getNavigationIcon(): string|BackedEnum|null
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
                                Select::make('game_type')
                                    ->label('Game Type')
                                    ->options([
                                        'board_game' => 'Board Game',
                                        'ttrpg' => 'TTRPG',
                                    ])
                                    ->default('board_game')
                                    ->required(),
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
                            ->required()
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
                                    ->options(collect(ContentLanguage::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()]))
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
                                        'scheduled' => 'Scheduled',
                                        'canceled' => 'Canceled',
                                        'completed' => 'Completed',
                                    ])
                                    ->default('scheduled')
                                    ->required(),
                            ]),
                    ]),

                // ── Attendance Info ────────────────────────────────
                Section::make('Attendance Resolution')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Placeholder::make('attendance_resolution_method_display')
                                    ->label('Resolution Method')
                                    ->content(fn ($record) => $record?->attendance_resolution_method
                                        ? AttendanceResolutionMethod::tryFrom($record->attendance_resolution_method)?->label() ?? $record->attendance_resolution_method
                                        : 'Not resolved'),
                                Placeholder::make('attendance_resolved_at_display')
                                    ->label('Resolved At')
                                    ->content(fn ($record) => $record?->attendance_resolved_at?->format('M j, Y g:i A') ?? '—'),
                                Placeholder::make('attendance_window_display')
                                    ->label('Reporting Window')
                                    ->content(fn ($record) => $record?->attendance_window_opens_at
                                        ? $record->attendance_window_opens_at->format('M j, Y g:i A').' → '.$record->attendance_window_closes_at?->format('M j, Y g:i A')
                                        : '—'),
                            ]),
                    ])
                    ->visible(fn ($record) => $record?->status?->value === 'completed'),

                // ── SEO Overrides ─────────────────────────────
                SeoFields::make(),
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
                ImageColumn::make('owner.avatar_url')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn (): ?string => null)
                    ->extraImgAttributes(['aria-hidden' => 'true']),
                TextColumn::make('owner.name')
                    ->label('Game Master')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('game_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (GameType $state): string => match ($state) {
                        GameType::BoardGame => 'info',
                        GameType::Ttrpg => 'warning',
                        GameType::Gathering => 'success',
                    })
                    ->formatStateUsing(fn (GameType $state): string => $state->label())
                    ->toggleable(),
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
                    ->color(fn (Visibility $state): string => match ($state) {
                        Visibility::Public => 'success',
                        Visibility::Protected => 'warning',
                        Visibility::Private => 'danger',
                    })
                    ->formatStateUsing(fn (Visibility $state): string => ucfirst($state->value)),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (GameStatus $state): string => match ($state) {
                        GameStatus::Scheduled => 'info',
                        GameStatus::Completed => 'success',
                        GameStatus::Canceled => 'danger',
                    })
                    ->formatStateUsing(fn (GameStatus $state): string => $state->label()),
                TextColumn::make('participants_count')
                    ->label('Players')
                    ->counts('participants')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ParticipantsRelationManager::class,
            AttendanceReportsRelationManager::class,
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
