<?php

namespace App\Filament\Resources\GameResource\RelationManagers;

use App\Enums\AttendanceStatus;
use App\Filament\Concerns\OverridesAttendance;
use App\Models\GameParticipant;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AttendanceReportsRelationManager extends RelationManager
{
    use OverridesAttendance;

    protected static string $relationship = 'attendanceReports';

    protected static ?string $title = 'Attendance Reports';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reporter.name')
                    ->label('Reporter')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('reported.name')
                    ->label('Reported')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AttendanceStatus $state): string => match ($state) {
                        AttendanceStatus::Attended => 'success',
                        AttendanceStatus::NoShow => 'danger',
                        AttendanceStatus::Excused => 'info',
                        AttendanceStatus::LateCancel => 'warning',
                        AttendanceStatus::CancelledEarly => 'gray',
                    })
                    ->formatStateUsing(fn (AttendanceStatus $state): string => $state->label()),
                TextColumn::make('reason')
                    ->limit(50)
                    ->wrap()
                    ->toggleable()
                    ->default('—'),
                TextColumn::make('weight_applied')
                    ->label('Weight')
                    ->numeric(2)
                    ->sortable(),
                TextColumn::make('is_corroborated')
                    ->label('Corroborated')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
                TextColumn::make('quarantined')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'danger' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Quarantined' : 'Clean'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(
                        collect(AttendanceStatus::cases())->mapWithKeys(
                            fn (AttendanceStatus $case) => [$case->value => $case->label()]
                        )
                    ),
            ])
            ->recordActions([
                Action::make('override')
                    ->label('Override Status')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn ($record) => $record->reported_id !== null)
                    ->requiresConfirmation()
                    ->modalHeading('Override Attendance Status')
                    ->modalDescription('This will change the participant\'s attendance status and recalculate their reliability score. The change is logged with your admin identity. Use with care.')
                    ->form(fn () => $this->attendanceOverrideFormFields())
                    ->action(function ($record, array $data) {
                        $participant = GameParticipant::where('game_id', $record->game_id)
                            ->where('user_id', $record->reported_id)
                            ->first();

                        if (! $participant) {
                            Notification::make()
                                ->title('No participant record found for this reported user.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $this->executeAttendanceOverride($participant, $data);
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
