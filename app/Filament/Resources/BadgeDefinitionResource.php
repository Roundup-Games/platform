<?php

namespace App\Filament\Resources;

use App\Enums\BadgeMode;
use App\Filament\Resources\BadgeDefinitionResource\Pages;
use App\Filament\Resources\BadgeDefinitionResource\RelationManagers;
use App\Models\BadgeDefinition;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * The badge maintaining surface (D186, spec §2.3): curation over the
 * registry mirror, not row creation.
 *
 * Definitions are CODE-owned — the registry is truth, mirrored into
 * badge_definitions by `badges:sync` (D171 CityResource precedent for
 * "auto-provisioned rows, admin curates"): no create page exists. Sync
 * deliberately never touches is_active or metadata, so exactly those two
 * are the admin-editable surface here — retirement (the toggle action)
 * and display metadata (the edit form). Identity columns (key, category,
 * mode, icon, sort_order) are sync-owned and render as read-only
 * placeholders, never editable inputs.
 */
class BadgeDefinitionResource extends Resource
{
    protected static ?string $model = BadgeDefinition::class;

    protected static ?int $navigationSort = 7;

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return Heroicon::OutlinedTrophy;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Registry Identity')
                    ->description('Code is the source of truth — the registry is mirrored by `badges:sync`. Identity never changes here.')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                // Placeholders: display-only, never dehydrated —
                                // a readOnly() input would still submit its
                                // (formatted) state and overwrite sync-owned
                                // columns (CityResource identity precedent).
                                Placeholder::make('key')
                                    ->label('Badge key')
                                    ->content(fn (?BadgeDefinition $record): string => (string) $record?->key),
                                Placeholder::make('category')
                                    ->label('Category')
                                    ->content(fn (?BadgeDefinition $record): string => $record?->category !== null ? Str::headline($record->category->value) : ''),
                                Placeholder::make('mode')
                                    ->label('Mode')
                                    ->content(fn (?BadgeDefinition $record): string => $record?->mode !== null ? Str::headline($record->mode->value) : ''),
                                Placeholder::make('icon')
                                    ->label('Icon')
                                    ->content(fn (?BadgeDefinition $record): string => $record?->icon !== null ? $record->icon : '—'),
                                Placeholder::make('sort_order')
                                    ->label('Sort order')
                                    ->content(fn (?BadgeDefinition $record): string => $record?->sort_order !== null ? (string) $record->sort_order : '—'),
                            ]),
                    ]),

                Section::make('Display Metadata')
                    ->description('Display overrides layered over the registry defaults. This and retirement are the only definition fields admins own — `badges:sync` never touches them.')
                    ->schema([
                        Textarea::make('metadata')
                            ->label('Display metadata (JSON)')
                            ->rows(6)
                            ->columnSpanFull()
                            ->afterStateHydrated(static function (Textarea $component): void {
                                /** @var array<string, mixed>|null $state */
                                $state = $component->getState();

                                $component->state($state === null || $state === []
                                    ? null
                                    : json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                            })
                            // `json` semantics with a blank-tolerance: an
                            // empty textarea clears the overrides (null),
                            // anything else must decode to a JSON object.
                            ->rules([
                                fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                                    if (blank($value)) {
                                        return;
                                    }

                                    $decoded = is_string($value) ? json_decode($value, true) : null;

                                    if (! is_array($decoded)) {
                                        $fail('The :attribute must be a valid JSON object.');
                                    }
                                },
                            ])
                            ->dehydrateStateUsing(static function (?string $state): ?array {
                                if (blank($state)) {
                                    return null;
                                }

                                /** @var array<string, mixed>|null $decoded */
                                $decoded = json_decode($state, true);

                                return is_array($decoded) ? $decoded : null;
                            })
                            ->helperText('JSON object of display overrides (e.g. {"flavor":"Curated copy"}). Leave empty to clear. Registry identity (key, tiers, icon…) is never editable.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->copyable()
                    ->copyMessage('Badge key copied')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Name')
                    ->formatStateUsing(fn (BadgeDefinition $record): string => (string) __($record->name_key))
                    ->searchable(false),
                TextColumn::make('mode')
                    ->badge()
                    ->formatStateUsing(fn (BadgeDefinition $record): string => Str::headline($record->mode->value))
                    ->color(fn (BadgeDefinition $record): string => match ($record->mode) {
                        BadgeMode::OneTime => 'gray',
                        BadgeMode::Milestone => 'info',
                        BadgeMode::Recurring => 'success',
                        BadgeMode::Requalifying => 'warning',
                    })
                    ->sortable(),
                TextColumn::make('tiers')
                    ->label('Tiers')
                    ->formatStateUsing(fn (BadgeDefinition $record): string => self::formatTiers($record))
                    ->toggleable(),
                TextColumn::make('badgeUsers_count')
                    ->counts('badgeUsers')
                    ->label('Holders')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedCheckCircle)
                    ->falseIcon(Heroicon::OutlinedPauseCircle)
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->sortable(),
                TextColumn::make('is_dormant')
                    ->label('Dormant')
                    ->badge()
                    ->getStateUsing(fn (BadgeDefinition $record): ?string => $record->is_dormant ? 'Dormant' : null)
                    ->color('warning')
                    ->tooltip('Registered shape whose signal has not shipped yet (D189/D190) — the engine refuses awards.'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All badges')
                    ->trueLabel('Active only')
                    ->falseLabel('Retired only'),
                TernaryFilter::make('is_dormant')
                    ->label('Dormant'),
            ])
            ->defaultGroup(
                Group::make('category')
                    ->getTitleFromRecordUsing(fn (BadgeDefinition $record): string => Str::headline($record->category->value))
                    ->titlePrefixedWithLabel(false),
            )
            ->defaultSort('sort_order')
            ->emptyStateHeading('No badge definitions synced yet')
            ->emptyStateDescription('Definitions mirror the code registry — run `php artisan badges:sync` and they appear here. They are never hand-created.')
            ->recordActions([
                // Single toggle action with a dynamic label: retiring
                // refuses NEW awards only (BadgeService returns
                // RefusedInactive); existing holds stay displayed, and
                // reactivation is a symmetric flip.
                Action::make('toggleActive')
                    ->label(fn (BadgeDefinition $record): string => $record->is_active ? 'Retire' : 'Reactivate')
                    ->icon(fn (BadgeDefinition $record): BackedEnum => $record->is_active
                        ? Heroicon::OutlinedPauseCircle
                        : Heroicon::OutlinedPlayCircle)
                    ->color(fn (BadgeDefinition $record): string => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (BadgeDefinition $record): string => $record->is_active
                        ? "Retire badge [{$record->key}]?"
                        : "Reactivate badge [{$record->key}]?")
                    ->modalDescription(fn (BadgeDefinition $record): string => $record->is_active
                        ? 'Retirement refuses new awards but never removes existing holds or their history. Reactivating later is always possible.'
                        : 'The badge opens for new awards again.')
                    ->action(function (BadgeDefinition $record): void {
                        $record->update(['is_active' => ! $record->is_active]);

                        Notification::make()
                            ->success()
                            ->title($record->is_active ? "Badge [{$record->key}] reactivated" : "Badge [{$record->key}] retired")
                            ->send();
                    }),
                EditAction::make(),
            ]);
    }

    /**
     * Threshold-sorted tier list (e.g. "bronze 1 · silver 10 · gold 50").
     *
     * Postgres jsonb does NOT preserve key order — ordering lives in the
     * threshold values (same normalization as BadgeService::tierRank and
     * the BadgesSyncTest ksort note), so sort by threshold before render.
     */
    private static function formatTiers(BadgeDefinition $record): string
    {
        $tiers = collect($record->tiers ?? [])
            ->filter(fn (mixed $threshold): bool => is_int($threshold))
            ->sort();

        if ($tiers->isEmpty()) {
            return '—';
        }

        return $tiers->map(fn (int $threshold, string $tier): string => "{$tier} {$threshold}")->implode(' · ');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\BadgeUsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBadgeDefinitions::route('/'),
            'edit' => Pages\EditBadgeDefinition::route('/{record}/edit'),
        ];
    }
}
