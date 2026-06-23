<?php

namespace App\Filament\Resources\CampaignResource\RelationManagers;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Filament\Concerns\RoutesParticipantTransitions;
use App\Models\Campaign;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ParticipantsRelationManager extends RelationManager
{
    use RoutesParticipantTransitions;

    protected static string $relationship = 'participants';

    protected static ?string $title = 'Participants';

    /**
     * The relation manager is read-only at the Filament layer: built-in
     * Create / Edit / Delete / Bulk-Delete are denied authorization. Every
     * participant mutation flows through the row transition actions
     * (RoutesParticipantTransitions), which route through ParticipantLifecycle
     * so admin writes carry the same audit trail, notifications, and roster
     * cascades as host- or user-initiated transitions.
     *
     * The prior EditAction / DeleteAction wrote status and role directly to
     * the model, bypassing the lifecycle's side-effects. Custom Action
     * instances are not gated by isReadOnly(); only the built-in
     * Create/Edit/Delete family and reorder are.
     *
     * @see GameResource\RelationManagers\ParticipantsRelationManager for the
     *      full rationale shared by both managers.
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('user.avatar_url')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn (): ?string => null)
                    ->extraImgAttributes(['aria-hidden' => 'true']),
                TextColumn::make('user.name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role')
                    ->badge()
                    ->color(fn ($state): string => match ((string) $state) {
                        ParticipantRole::Owner->value => 'warning',
                        ParticipantRole::Player->value => 'success',
                        ParticipantRole::Invited->value => 'info',
                        ParticipantRole::Applicant->value => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state): string => match ((string) $state) {
                        ParticipantStatus::Approved->value => 'success',
                        ParticipantStatus::Rejected->value => 'danger',
                        ParticipantStatus::Pending->value => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ...$this->participantTransitionActions($this->ownerRecordAsEntity()),
            ]);
    }

    private function ownerRecordAsEntity(): Campaign
    {
        /** @var Campaign $owner */
        $owner = $this->ownerRecord;

        return $owner;
    }
}
