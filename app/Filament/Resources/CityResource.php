<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CityResource\Pages;
use App\Models\City;
use App\Models\Location;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use LaraZeus\SpatieTranslatable\Resources\Concerns\Translatable;

class CityResource extends Resource
{
    use Translatable;

    protected static ?string $model = City::class;

    protected static ?int $navigationSort = 7;

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedBuildingOffice2;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('City Curation')
                    ->description('One row per curated city hub. The slug is the join key against the runtime cluster resolution — always derived, never typed.')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('city')
                                    ->label('City')
                                    ->required()
                                    ->searchable()
                                    ->live()
                                    ->options(fn (?City $record): array => static::locationCityOptions($record?->city))
                                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                                        $set('slug', Str::slug((string) $state));
                                    })
                                    ->rules([
                                        fn (?City $record): Closure => static::derivedSlugCollisionRule($record),
                                    ])
                                    ->helperText('Real location cities only — the hub slug is derived from this value.'),
                                TextInput::make('slug')
                                    ->label('Slug')
                                    ->readOnly()
                                    ->unique(ignoreRecord: true)
                                    ->helperText('Derived from the selected city (/cities/{slug}) — München slugs to munchen.'),
                                Select::make('region_prefix')
                                    ->label('Region prefix')
                                    ->options(fn (Get $get): array => static::regionPrefixOptions($get('city')))
                                    ->visible(fn (Get $get): bool => count(static::regionPrefixOptions($get('city'))) > 1)
                                    ->helperText('Offered only when this city spans multiple geohash regions — pins an ambiguous cluster to one.'),
                            ]),
                    ]),

                Section::make('Visibility')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Toggle::make('featured')
                                    ->helperText('Force-qualifies the hub over the activity thresholds and surfaces it on the /discover featured rail.'),
                                Toggle::make('hidden')
                                    ->helperText('Removes the hub from every public surface (hub, sitemap entry, rail). Hidden wins over featured.'),
                            ]),
                    ]),

                Section::make('Intro')
                    ->description('Curated hero copy rendered on the city hub, per locale. Falls back to the generated copy when empty.')
                    ->schema([
                        Textarea::make('intro')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('slug')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('city')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('upcoming_activity_count')
                    ->label('Upcoming Activity')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('verified_venues_count')
                    ->label('Verified Venues')
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('featured')
                    ->boolean()
                    ->sortable(),
                IconColumn::make('hidden')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('recomputed_at')
                    ->label('Recomputed')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('featured'),
                TernaryFilter::make('hidden'),
            ])
            ->defaultSort('city', 'asc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCities::route('/'),
            'create' => Pages\CreateCity::route('/create'),
            'edit' => Pages\EditCity::route('/{record}/edit'),
        ];
    }

    /**
     * Distinct real Location city values as select options. The city is
     * ALWAYS chosen from what locations actually carry — the slug is
     * derived, not hand-picked, so free text would let admins curate
     * cities that resolve to no cluster. On edit, the row's stored
     * display name stays selectable even when no location spells it that
     * way anymore (renames must not strand the form on an empty select).
     *
     * @return array<string, string>
     */
    public static function locationCityOptions(?string $include = null): array
    {
        $options = Location::query()
            ->whereNotNull('city')
            ->distinct()
            ->orderBy('city')
            ->pluck('city', 'city')
            ->all();

        if (is_string($include) && $include !== '' && ! array_key_exists($include, $options)) {
            $options[$include] = $include;
            ksort($options);
        }

        return $options;
    }

    /**
     * Geohash region candidates for one city, keyed by the 3-char prefix
     * with per-prefix location counts as labels ("u33 — 4 locations").
     * Offered only when a city spans more than one region — the exact
     * shape CityDirectoryService calls ambiguous (same city name in
     * different regions) and region_prefix disambiguates.
     *
     * @return array<string, string>
     */
    public static function regionPrefixOptions(?string $city): array
    {
        if (! is_string($city) || $city === '') {
            return [];
        }

        return Location::query()
            ->where('city', $city)
            ->whereNotNull('geohash_4')
            ->pluck('geohash_4')
            ->map(fn (string $geohash): string => substr($geohash, 0, 3))
            ->countBy()
            ->sortKeys()
            ->mapWithKeys(fn (int $count, string $prefix): array => [
                $prefix => sprintf('%s — %d %s', $prefix, $count, Str::plural('location', $count)),
            ])
            ->all();
    }

    /**
     * Validation rule for the city select: the slug this city derives
     * (Str::slug — ASCII-folds umlauts, never guesses "oe") must not
     * already be curated on another row. Anchored on the city value (not
     * the derived slug field) so collisions surface as an admin-visible
     * error on every write path, independent of client-side state; the
     * current record's own slug is ignored on edit.
     */
    public static function derivedSlugCollisionRule(?City $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $slug = Str::slug($value);

            $query = City::query()->where('slug', $slug);

            if ($record instanceof City) {
                $query->whereKeyNot($record->getKey());
            }

            if ($query->exists()) {
                $fail("The city \"{$value}\" derives the slug \"{$slug}\", which is already curated. Edit the existing row instead.");
            }
        };
    }
}
