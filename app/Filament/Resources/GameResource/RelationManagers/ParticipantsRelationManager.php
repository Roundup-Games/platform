<?php

namespace App\Filament\Resources\GameResource\RelationManagers;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Filament\Concerns\OverridesAttendance;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ParticipantsRelationManager extends RelationManager
{
    use OverridesAttendance;

    protected static string $relationship = 'participants';

    protected static ?string $title = 'Participants';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('User')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->required(),
                Select::make('role')
                    ->options(collect(ParticipantRole::cases())->mapWithKeys(
                        fn (ParticipantRole $role) => [$role->value => $role->label()]
                    ))
                    ->required()
                    ->default(ParticipantRole::Player->value),
                Select::make('status')
                    ->options([
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'pending' => 'Pending',
                    ])
                    ->required()
                    ->default('pending'),
            ]);
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
                    ->visible(fn ($record) => $record->status?->value === 'approved' && $this->ownerRecord->status?->value === 'completed')
                    ->requiresConfirmation()
                    ->modalHeading('Override Attendance Status')
                    ->modalDescription('This will change the participant\'s attendance status and recalculate their reliability score. The change is logged with your admin identity. Use with care.')
                    ->form(fn () => $this->attendanceOverrideFormFields())
                    ->action(fn ($record, array $data) => $this->executeAttendanceOverride($record, $data)),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
