<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CampaignResource\Pages;
use App\Filament\Resources\CampaignResource\RelationManagers\ParticipantsRelationManager;
use App\Models\Campaign;
use Filament\Forms\Components\Grid;
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

class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static ?int $navigationSort = 4;

    public static function getNavigationIcon(): string | BackedEnum | null
    {
        return Heroicon::OutlinedBookOpen;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Campaign Details')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Select::make('owner_id')
                                    ->label('Owner')
                                    ->relationship('owner', 'name')
                                    ->searchable()
                                    ->required(),
                                Select::make('game_system_id')
                                    ->label('Game System')
                                    ->relationship('gameSystem', 'name')
                                    ->searchable()
                                    ->preload(),
                                Select::make('recurrence')
                                    ->options([
                                        'weekly' => 'Weekly',
                                        'biweekly' => 'Bi-weekly',
                                        'monthly' => 'Monthly',
                                        'custom' => 'Custom',
                                    ]),
                                TextInput::make('session_duration')
                                    ->label('Session Duration (hours)')
                                    ->numeric()
                                    ->step(0.5),
                                TextInput::make('price_per_session')
                                    ->label('Price per Session')
                                    ->numeric()
                                    ->prefix('$')
                                    ->step(0.01)
                                    ->default(0),
                            ]),
                    ]),

                Section::make('Description')
                    ->schema([
                        Textarea::make('description')
                            ->rows(4)
                            ->maxLength(5000),
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
                                        'recruiting' => 'Recruiting',
                                        'active' => 'Active',
                                        'on_hiatus' => 'On Hiatus',
                                        'completed' => 'Completed',
                                        'cancelled' => 'Cancelled',
                                    ])
                                    ->default('draft')
                                    ->required(),
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
                    ->label('Owner')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('gameSystem.name')
                    ->label('System')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('recurrence')
                    ->badge()
                    ->toggleable(),
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
                        'recruiting' => 'info',
                        'active' => 'success',
                        'on_hiatus' => 'warning',
                        'completed' => 'gray',
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
            'index' => Pages\ListCampaigns::route('/'),
            'create' => Pages\CreateCampaign::route('/create'),
            'edit' => Pages\EditCampaign::route('/{record}/edit'),
        ];
    }
}
