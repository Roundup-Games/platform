<?php

namespace App\Filament\Resources\GameResource\RelationManagers;

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Filament\Concerns\OverridesAttendance;
use App\Filament\Concerns\RoutesParticipantTransitions;
use App\Models\Game;
use App\Models\GameParticipant;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ParticipantsRelationManager extends RelationManager
{
    use OverridesAttendance;
    use RoutesParticipantTransitions;

    protected static string $relationship = 'participants';

    protected static ?string $title = 'Participants';

    /**
     * The relation manager is read-only at the Filament layer: built-in
     * Create / Edit / Delete / Bulk-Delete are denied authorization, and
     * reorder is disabled. Every participant mutation flows through the row
     * transition actions (RoutesParticipantTransitions), which route through
     * ParticipantLifecycle so admin writes carry the same audit trail,
     * notifications, capacity checks, and roster cascades as host- or
     * user-initiated transitions.
     *
     * The prior EditAction / DeleteAction wrote status and role directly to
     * the model — the GameParticipantObserver only invalidates dashboard
     * cache on Eloquent writes, it does not re-run those side-effects — so a
     * direct admin write produced an audit-incomplete, notification-silent,
     * roster-inconsistent row.
     *
     * Custom Action instances (transition actions + Override Attendance) are
     * not gated by isReadOnly(); only the built-in Create/Edit/Delete family
     * and reorder are. See Filament\Resources\RelationManagers\RelationManager.
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
                    ->color(fn (ParticipantRole $state): string => match ($state) {
                        ParticipantRole::Owner => 'warning',
                        ParticipantRole::Player => 'success',
                        ParticipantRole::Invited => 'info',
                        ParticipantRole::Applicant => 'gray',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (ParticipantStatus $state): string => match ($state) {
                        ParticipantStatus::Approved => 'success',
                        ParticipantStatus::Rejected => 'danger',
                        ParticipantStatus::Pending => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('attendance_status')
                    ->label('Attendance')
                    ->badge()
                    ->color(fn ($record) => match ($record->attendance_status) {
                        AttendanceStatus::Attended => 'success',
                        AttendanceStatus::NoShow => 'danger',
                        AttendanceStatus::Excused => 'info',
                        AttendanceStatus::LateCancel => 'warning',
                        AttendanceStatus::CancelledEarly => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?AttendanceStatus $state): string => $state?->label() ?? '—')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('attendance_weight')
                    ->label('Weight')
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format((float) $state, 2) : '—')
                    ->toggleable(),
                IconColumn::make('attendance_disputed')
                    ->label('Disputed')
                    ->icon(fn ($record) => $record->attendance_disputed_at ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-minus')
                    ->color(fn ($record) => $record->attendance_disputed_at ? 'danger' : 'gray')
                    ->tooltip(fn ($record) => $record->attendance_disputed_at?->format('M j, Y g:i A'))
                    ->toggleable(),
                TextColumn::make('attendance_reported_at')
                    ->label('Reported At')
                    ->formatStateUsing(fn ($state) => $state ? $state->format('M j, Y g:i A') : '—')
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('overrideAttendance')
                    ->label('Override Attendance')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(function ($record): bool {
                        if (! $record instanceof GameParticipant) {
                            return false;
                        }

                        /** @var Game $owner */
                        $owner = $this->ownerRecord;

                        return $record->status?->value === 'approved'
                            && $owner->status?->value === 'completed';
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Override Attendance Status')
                    ->modalDescription('This will change the participant\'s attendance status and recalculate their reliability score. The change is logged with your admin identity. Use with care.')
                    ->form(fn () => $this->attendanceOverrideFormFields())
                    ->action(fn ($record, array $data) => $this->executeAttendanceOverride($record, $data)),
                ...$this->participantTransitionActions($this->ownerRecordAsEntity()),
            ]);
    }

    private function ownerRecordAsEntity(): Game
    {
        /** @var Game $owner */
        $owner = $this->ownerRecord;

        return $owner;
    }
}
