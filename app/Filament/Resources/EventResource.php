<?php

namespace App\Filament\Resources;

use App\Filament\Components\SeoFields;
use App\Filament\Resources\EventResource\Pages;
use App\Filament\Resources\EventResource\RelationManagers\RegistrationsRelationManager;
use App\Filament\Resources\EventResource\RelationManagers\AnnouncementsRelationManager;
use App\Models\Event;
use App\Enums\ContentLanguage;
use App\Enums\EventStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LaraZeus\SpatieTranslatable\Resources\Concerns\Translatable;

class EventResource extends Resource
{
    use Translatable;

    protected static ?string $model = Event::class;

    protected static ?int $navigationSort = 5;

    public static function getNavigationIcon(): string | BackedEnum | null
    {
        return Heroicon::OutlinedCalendar;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Event Details')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255),
                                Select::make('organizer_id')
                                    ->label('Organizer')
                                    ->relationship('organizer', 'name')
                                    ->searchable()
                                    ->required(),
                                Select::make('type')
                                    ->options([
                                        'tournament' => 'Tournament',
                                        'convention' => 'Convention',
                                        'game_day' => 'Game Day',
                                        'league' => 'League',
                                        'other' => 'Other',
                                    ])
                                    ->required(),
                                Select::make('status')
                                    ->options([
                                        'draft' => 'Draft',
                                        'published' => 'Published',
                                        'registration_open' => 'Registration Open',
                                        'registration_closed' => 'Registration Closed',
                                        'in_progress' => 'In Progress',
                                        'completed' => 'Completed',
                                        'cancelled' => 'Cancelled',
                                    ])
                                    ->default('draft')
                                    ->required(),
                                Select::make('language')
                                    ->label('Content Language')
                                    ->options(collect(ContentLanguage::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()]))
                                    ->default('en'),
                                Textarea::make('short_description')
                                    ->maxLength(500)
                                    ->columnSpanFull(),
                                Textarea::make('description')
                                    ->rows(4)
                                    ->maxLength(10000)
                                    ->columnSpanFull(),
                            ]),
                    ]),

                Section::make('Schedule')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                DatePicker::make('start_date')
                                    ->required(),
                                DatePicker::make('end_date')
                                    ->required(),
                                DateTimePicker::make('registration_opens_at')
                                    ->label('Registration Opens'),
                                DateTimePicker::make('registration_closes_at')
                                    ->label('Registration Closes'),
                            ]),
                        Textarea::make('rules')
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('schedule')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('Venue')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('venue_name')
                                    ->maxLength(255),
                                TextInput::make('venue_address')
                                    ->maxLength(255),
                                TextInput::make('city')
                                    ->maxLength(255),
                                TextInput::make('country')
                                    ->maxLength(255),
                                TextInput::make('postal_code')
                                    ->maxLength(20),
                            ]),
                    ]),

                Section::make('Registration & Capacity')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Select::make('registration_type')
                                    ->options([
                                        'individual' => 'Individual',
                                        'team' => 'Team',
                                        'both' => 'Both',
                                    ])
                                    ->default('individual')
                                    ->required(),
                                TextInput::make('max_participants')
                                    ->label('Max Participants')
                                    ->numeric()
                                    ->minValue(1),
                                TextInput::make('max_teams')
                                    ->label('Max Teams')
                                    ->numeric()
                                    ->minValue(1),
                                TextInput::make('team_registration_fee')
                                    ->label('Team Fee (cents)')
                                    ->numeric()
                                    ->prefix('¢'),
                                TextInput::make('individual_registration_fee')
                                    ->label('Individual Fee (cents)')
                                    ->numeric()
                                    ->prefix('¢'),
                                TextInput::make('min_players_per_team')
                                    ->label('Min Players/Team')
                                    ->numeric()
                                    ->minValue(1),
                                TextInput::make('max_players_per_team')
                                    ->label('Max Players/Team')
                                    ->numeric()
                                    ->minValue(1),
                            ]),
                    ]),

                Section::make('Visibility')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Toggle::make('is_public')
                                    ->label('Public')
                                    ->default(true),
                                Toggle::make('is_featured')
                                    ->label('Featured'),
                            ]),
                    ]),

                Section::make('Contact')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('contact_email')
                                    ->email()
                                    ->maxLength(255),
                                TextInput::make('contact_phone')
                                    ->tel()
                                    ->maxLength(255),
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
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                ImageColumn::make('organizer.avatar_url')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn (): ?string => null)
                    ->extraImgAttributes(['aria-hidden' => 'true']),
                TextColumn::make('organizer.name')
                    ->label('Organizer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->searchable(),
                TextColumn::make('start_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('end_date')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (EventStatus $state): string => match ($state) {
                        EventStatus::Published => 'info',
                        EventStatus::RegistrationOpen => 'success',
                        EventStatus::RegistrationClosed => 'warning',
                        EventStatus::InProgress => 'warning',
                        EventStatus::Completed => 'gray',
                        EventStatus::Cancelled => 'danger',
                        default => 'gray',
                    }),
                IconColumn::make('is_public')
                    ->label('Public')
                    ->boolean(),
                IconColumn::make('is_featured')
                    ->label('Featured')
                    ->boolean(),
                TextColumn::make('registrations_count')
                    ->label('Registrations')
                    ->counts('registrations')
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
            RegistrationsRelationManager::class,
            AnnouncementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEvents::route('/'),
            'create' => Pages\CreateEvent::route('/create'),
            'edit' => Pages\EditEvent::route('/{record}/edit'),
        ];
    }

}
