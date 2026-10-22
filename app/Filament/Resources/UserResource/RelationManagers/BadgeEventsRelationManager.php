<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Enums\BadgeEventAction;
use App\Models\BadgeEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The per-user badge audit trail (D186, spec §2.3): every badge_events
 * row for this user across all badges, straight through their
 * badge_user holds (User::badgeEvents).
 *
 * Read-only by design — the trail is an append-only ledger of facts
 * (the table has no updated_at); correction flows through the
 * BadgesRelationManager's grant/revoke, which append their own rows.
 */
class BadgeEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'badgeEvents';

    protected static ?string $title = 'Badge audit trail';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('action')
                    ->badge()
                    ->formatStateUsing(fn (BadgeEvent $record): string => ucfirst($record->action->value))
                    ->color(fn (BadgeEventAction $state): string => match ($state) {
                        BadgeEventAction::Revoked => 'danger',
                        BadgeEventAction::Lapsed => 'warning',
                        BadgeEventAction::Requalified, BadgeEventAction::Upgraded => 'info',
                        BadgeEventAction::Awarded => 'success',
                    })
                    ->sortable(),
                TextColumn::make('source')
                    ->badge()
                    ->formatStateUsing(fn (BadgeEvent $record): string => ucfirst($record->source->value))
                    ->sortable(),
                TextColumn::make('actor.name')
                    ->label('Actor')
                    ->placeholder('System')
                    ->searchable(),
                TextColumn::make('reason')
                    ->limit(40)
                    ->tooltip(fn (BadgeEvent $record): ?string => $record->reason),
                TextColumn::make('evidence_type')
                    ->label('Evidence')
                    ->badge()
                    ->formatStateUsing(fn (BadgeEvent $record): ?string => $record->evidence_type),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
