<?php

namespace App\Filament\Resources;

use App\Enums\VenueType;
use App\Filament\Resources\LocationResource\Pages;
use App\Models\Location;
use App\Services\LocationMergeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Select as FormSelect;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;

class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static ?int $navigationSort = 6;

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedMapPin;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Address and Geocoding')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                                TextInput::make('address')
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                                TextInput::make('city')
                                    ->maxLength(255),
                                TextInput::make('postal_code')
                                    ->maxLength(20),
                                TextInput::make('country')
                                    ->maxLength(255),
                                TextInput::make('latitude')
                                    ->numeric()
                                    ->step(0.0000001),
                                TextInput::make('longitude')
                                    ->numeric()
                                    ->step(0.0000001),
                                TextInput::make('place_id')
                                    ->label('Place ID')
                                    ->disabled()
                                    ->maxLength(255),
                                TextInput::make('geohash_4')
                                    ->label('Geohash')
                                    ->disabled()
                                    ->maxLength(255),
                            ]),
                    ]),

                Section::make('Source and Dedup')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('source')
                                    ->disabled()
                                    ->maxLength(50),
                                Textarea::make('metadata')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ]),
                    ]),

                Section::make('Venue Profile')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Toggle::make('is_verified')
                                    ->label('Verified Venue'),
                                Select::make('venue_type')
                                    ->label('Venue Type')
                                    ->options(collect(VenueType::cases())->mapWithKeys(fn (VenueType $case) => [$case->value => $case->label()])),
                                TextInput::make('website_url')
                                    ->label('Website')
                                    ->url()
                                    ->maxLength(255),
                                Select::make('managed_by')
                                    ->label('Managed By')
                                    ->relationship('managedBy', 'name')
                                    ->searchable(),
                                Textarea::make('venue_notes')
                                    ->rows(3)
                                    ->columnSpanFull(),
                                Textarea::make('venue_metadata')
                                    ->rows(3)
                                    ->columnSpanFull()
                                    ->disabled()
                                    ->formatStateUsing(fn ($state): string => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : ($state ?? '')),
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
                TextColumn::make('drift_status')
                    ->label('Drift')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'duplicate' => 'warning',
                        'stale_geocode' => 'danger',
                        default => 'gray',
                    })
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('address')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('city')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('country')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('latitude')
                    ->toggleable()
                    ->formatStateUsing(fn ($state): string => $state !== null ? number_format((float) $state, 4) : '-'),
                TextColumn::make('longitude')
                    ->toggleable()
                    ->formatStateUsing(fn ($state): string => $state !== null ? number_format((float) $state, 4) : '-'),
                TextColumn::make('source')
                    ->badge()
                    ->toggleable(),
                IconColumn::make('is_verified')
                    ->label('Verified')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('venue_type')
                    ->label('Venue Type')
                    ->badge()
                    ->toggleable()
                    ->formatStateUsing(fn (?VenueType $state): string => $state?->label() ?? '-'),
                TextColumn::make('games_count')
                    ->label('Games')
                    ->counts('games')
                    ->toggleable(),
                TextColumn::make('events_count')
                    ->label('Events')
                    ->counts('events')
                    ->toggleable(),
                TextColumn::make('users_count')
                    ->label('Users')
                    ->counts('users')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('drift_status')
                    ->label('Drift Status')
                    ->options([
                        'clean' => 'Clean',
                        'duplicate' => 'Duplicate',
                        'stale_geocode' => 'Stale Geocode',
                    ]),
                TernaryFilter::make('is_verified')
                    ->label('Verified'),
                SelectFilter::make('venue_type')
                    ->label('Venue Type')
                    ->options(collect(VenueType::cases())->mapWithKeys(fn (VenueType $case) => [$case->value => $case->label()])),
                SelectFilter::make('source')
                    ->label('Source'),
                SelectFilter::make('country')
                    ->label('Country'),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('verify')
                    ->label('Verify as Venue')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Verify Location as Venue')
                    ->modalDescription('Mark this location as a verified venue. You will be prompted to select a venue type.')
                    ->visible(fn (Location $record): bool => ! $record->is_verified)
                    ->form([
                        FormSelect::make('venue_type')
                            ->label('Venue Type')
                            ->required()
                            ->options(collect(VenueType::cases())->mapWithKeys(fn (VenueType $case) => [$case->value => $case->label()])),
                    ])
                    ->action(function (Location $record, array $data) {
                        $record->update([
                            'is_verified' => true,
                            'venue_type' => $data['venue_type'],
                        ]);
                        Log::info('Location verified via admin panel', [
                            'location_id' => $record->id,
                            'venue_type' => $data['venue_type'],
                            'verified_by' => auth()->id(),
                        ]);
                        Notification::make()
                            ->title('Location verified')
                            ->success()
                            ->send();
                    }),

                Action::make('unverify')
                    ->label('Unverify')
                    ->icon(Heroicon::OutlinedShieldExclamation)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Unverify Location')
                    ->modalDescription('This will remove the verified venue status and clear the venue type. Are you sure?')
                    ->visible(fn (Location $record): bool => (bool) $record->is_verified)
                    ->action(function (Location $record) {
                        $record->update([
                            'is_verified' => false,
                            'venue_type' => null,
                        ]);
                        Log::info('Location unverified via admin panel', [
                            'location_id' => $record->id,
                            'unverified_by' => auth()->id(),
                        ]);
                        Notification::make()
                            ->title('Location unverified')
                            ->warning()
                            ->send();
                    }),

                Action::make('merge')
                    ->label('Merge into…')
                    ->icon(Heroicon::OutlinedArrowRightCircle)
                    ->color('gray')
                    ->modalWidth(Width::Large)
                    ->modalHeading('Merge Location')
                    ->modalDescription('Select the target location to merge into. All references (games, events, campaigns, users) will be moved to the target, and this location will be deleted.')
                    ->form([
                        FormSelect::make('target_location_id')
                            ->label('Target Location')
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Location::query()
                                ->where('name', 'like', "%{$search}%")
                                ->limit(50)
                                ->pluck('name', 'id')
                                ->toArray())
                            ->getOptionLabelUsing(fn (string $value): ?string => Location::find($value)?->name),
                    ])
                    ->action(function (Location $record, array $data) {
                        $target = Location::find($data['target_location_id']);

                        if (! $target instanceof Location) {
                            Notification::make()
                                ->title('Target location not found')
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($target->id === $record->id) {
                            Notification::make()
                                ->title('Cannot merge a location into itself')
                                ->danger()
                                ->send();

                            return;
                        }

                        $result = app(LocationMergeService::class)->merge($record, $target, auth()->user());

                        Notification::make()
                            ->title('Locations merged successfully')
                            ->success()
                            ->body(sprintf(
                                'Moved %d games, %d events, %d campaigns, %d users to %s.',
                                $result['games'],
                                $result['events'],
                                $result['campaigns'],
                                $result['users'],
                                $target->name
                            ))
                            ->send();
                    }),
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
            LocationResource\RelationManagers\GamesRelationManager::class,
            LocationResource\RelationManagers\EventsRelationManager::class,
            LocationResource\RelationManagers\UsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLocations::route('/'),
            'create' => Pages\CreateLocation::route('/create'),
            'edit' => Pages\EditLocation::route('/{record}/edit'),
        ];
    }
}
