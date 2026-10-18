<?php

namespace App\Filament\Resources\BadgeDefinitionResource\RelationManagers;

use App\Filament\Resources\UserResource;
use App\Models\BadgeUser;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The holders of one badge definition (spec §2.3): inspection, not
 * mutation. Grant and revoke are per-user operations and live on
 * UserResource (S03-T5) — a hold is corrected in the context of the
 * user it belongs to, never bulk-edited on the definition.
 *
 * Rows are never deleted (spec principle 4: lapse honestly, never
 * vanish): revoked and lapsed holds stay listed with a state badge so
 * "who ever held this" stays answerable.
 */
class BadgeUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'badgeUsers';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('User')
                    ->weight('font-medium')
                    ->url(fn (BadgeUser $record): string => UserResource::getUrl('edit', ['record' => $record->user]))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tier')
                    ->badge()
                    ->formatStateUsing(fn (BadgeUser $record): string => $record->tier ?? 'Base')
                    ->sortable(),
                TextColumn::make('source')
                    ->badge()
                    ->formatStateUsing(fn (BadgeUser $record): string => ucfirst($record->source->value))
                    ->sortable(),
                // Revocation supersedes lapse (BadgeService semantics) —
                // a row can never be both, one column tells the story.
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->getStateUsing(fn (BadgeUser $record): ?string => $record->revoked_at !== null
                        ? 'Revoked'
                        : ($record->lapsed_at !== null ? 'Lapsed' : null))
                    ->color(fn (string $state): string => $state === 'Revoked' ? 'danger' : 'warning')
                    ->tooltip(fn (BadgeUser $record): ?string => $record->revoked_reason),
                TextColumn::make('first_awarded_at')
                    ->label('First awarded')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_awarded_at')
                    ->label('Last awarded')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('first_awarded_at', 'desc');
    }
}
