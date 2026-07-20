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
                                    ->label('Venue metadata (raw envelope)')
                                    ->hint('Read-only. Curate operational fields below; this shows the full JSON envelope including internal keys like approved_from_ticket.')
                                    ->rows(3)
                                    ->columnSpanFull()
                                    ->disabled()
                                    ->formatStateUsing(fn ($state): string => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : ($state ?? '')),
                            ]),
                    ]),

                Section::make('Operational Parameters')
                    ->description('Curated on the venue manager\'s behalf (M056/S05 Option A). Renders on the public venue page when at least one field is populated.')
                    ->schema([
                        Textarea::make('overlap_guidance')
                            ->label('Overlap guidance')
                            ->helperText('How overlapping or back-to-back sessions are handled at this venue.')
                            ->rows(2)
                            ->columnSpanFull()
                            ->maxLength(1000),
                        Textarea::make('fee_display')
                            ->label('Fee display')
                            ->helperText('Cover charge, table fee, or pricing summary shown to attendees.')
                            ->rows(2)
                            ->columnSpanFull()
                            ->maxLength(500),
                        Textarea::make('house_rules')
                            ->label('House rules')
                            ->helperText('Venue-specific rules attendees should know before arriving.')
                            ->rows(3)
                            ->columnSpanFull()
                            ->maxLength(2000),
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

                        if ($target->is($record)) {
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

    /**
     * The three operational-parameter virtual fields surfaced in the form
     * (M056/S05). Stored as sub-keys on the venue_metadata JSON envelope;
     * these are the only sub-keys that may reach the public venue page
     * (see the whitelist map in venue-detail.blade.php).
     */
    public const OPERATIONAL_PARAMETER_KEYS = [
        'overlap_guidance',
        'fee_display',
        'house_rules',
    ];

    /**
     * Normalize a single operational-parameter form value.
     *
     * Empty strings are normalized to null so the public venue page's
     * hide-when-empty check stays simple. Non-string values (null) pass
     * through unchanged.
     */
    public static function normalizeOperationalParameter(mixed $value): ?string
    {
        if (! is_string($value)) {
            // Non-string values (null at minimum — callers should never
            // pass arrays/scalars) normalize to null so the public venue
            // page hide-when-empty check stays simple.
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Pack the operational-parameter virtual fields into the venue_metadata
     * envelope, preserving any pre-existing sub-keys (approved_from_ticket,
     * proposed_by_user_id, geocoded_display_name, etc.) and dropping the
     * virtual keys so they never reach the Eloquent model as attribute names.
     *
     * Shared by CreateLocation (seed from []) and EditLocation (seed from
     * the persisted venue_metadata). The two pages previously each had
     * their own copy of this logic — now consolidated so future changes
     * (new field, normalization rule change) live in one place.
     *
     * @param  array<string, mixed>  $data  Form payload.
     * @param  array<string, mixed>|null  $existing  Persisted venue_metadata to merge into (null for create).
     * @return array<string, mixed> The payload with operational keys packed into 'venue_metadata'.
     */
    public static function packOperationalParameters(array $data, ?array $existing = null): array
    {
        $envelope = is_array($existing) ? $existing : [];

        foreach (self::OPERATIONAL_PARAMETER_KEYS as $key) {
            $value = array_key_exists($key, $data) ? $data[$key] : ($envelope[$key] ?? null);
            $envelope[$key] = self::normalizeOperationalParameter($value);
            unset($data[$key]);
        }

        $data['venue_metadata'] = $envelope;

        return $data;
    }
}
