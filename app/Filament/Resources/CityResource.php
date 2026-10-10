<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CityResource\Pages;
use App\Filament\Resources\CityResource\RelationManagers;
use App\Models\City;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use LaraZeus\SpatieTranslatable\Resources\Concerns\Translatable;

/**
 * City hub registry (D171). Rows are auto-provisioned from location
 * clusters — never hand-created — so this resource has no create flow:
 * curation is triage (edit a discovered row), not row creation. The
 * triage queue (discovered rows) surfaces as the navigation badge.
 */
class CityResource extends Resource
{
    use Translatable;

    protected static ?string $model = City::class;

    protected static ?int $navigationSort = 7;

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedBuildingOffice2;
    }

    /**
     * Triage queue size: discovered-but-never-curated hubs. Admins act on
     * this number; a steady zero means the registry is fully curated.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = City::query()->where('curation_state', 'discovered')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Discovered Hub')
                    ->description('Identity is derived from linked locations. Curation steers visibility and copy only — identity fields never change here.')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                // Placeholders: display-only, never dehydrated —
                                // a readOnly() TextInput would still submit its
                                // (formatted) state and overwrite identity.
                                Placeholder::make('city')
                                    ->label('City')
                                    ->content(fn (?City $record): string => (string) $record?->city),
                                Placeholder::make('slug')
                                    ->label('Hub URL')
                                    ->content(fn (?City $record): string => $record ? "/cities/{$record->slug} (frozen at discovery)" : ''),
                                Placeholder::make('region_prefix')
                                    ->label('Region cell')
                                    ->content(fn (?City $record): string => (string) $record?->region_prefix),
                                Placeholder::make('curation_state')
                                    ->label('Curation state')
                                    ->content(fn (?City $record): string => $record?->curation_state === 'curated' ? 'Curated' : 'Discovered — awaiting triage'),
                            ]),
                    ]),

                Section::make('Visibility')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Toggle::make('featured')
                                    ->helperText('Force-qualifies the hub over the activity thresholds and surfaces it on the /discover featured rail. Marking a discovered row featured also promotes it to curated.'),
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
                TextColumn::make('city')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Hub URL')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (City $record): string => "/cities/{$record->slug}")
                    ->copyable(),
                TextColumn::make('locations_count')
                    ->counts('locations')
                    ->label('Locations')
                    ->sortable(),
                TextColumn::make('curation_state')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'curated' ? 'Curated' : 'Discovered')
                    ->color(fn (string $state): string => $state === 'curated' ? 'success' : 'warning')
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
                SelectFilter::make('curation_state')
                    ->options([
                        'discovered' => 'Discovered (triage queue)',
                        'curated' => 'Curated',
                    ]),
                TernaryFilter::make('featured'),
                TernaryFilter::make('hidden'),
            ])
            ->defaultSort('city', 'asc')
            ->emptyStateHeading('No city hubs discovered yet')
            ->emptyStateDescription('Hubs appear here automatically as geocoded locations form clusters — nothing to create by hand. Check the Locations resource if you expected some.')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (City $record): bool => $record->locations()->doesntExist()),
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
            'edit' => Pages\EditCity::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\LocationsRelationManager::class,
        ];
    }
}
