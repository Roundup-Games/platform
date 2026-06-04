<?php

namespace App\Filament\Resources\GameResource\RelationManagers;

use App\Enums\AttendanceStatus;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\AttendanceService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AttendanceReportsRelationManager extends RelationManager
{
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
                \Filament\Tables\Filters\SelectFilter::make('status')
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
                    ->form([
                        Select::make('new_status')
                            ->label('New Attendance Status')
                            ->options(
                                collect(AttendanceStatus::cases())->mapWithKeys(
                                    fn (AttendanceStatus $case) => [$case->value => $case->label()]
                                )
                            )
                            ->required(),
                        Textarea::make('override_reason')
                            ->label('Reason for Override')
                            ->required()
                            ->maxLength(500),
                    ])
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

                        $admin = auth()->user();
                        $newStatus = AttendanceStatus::from($data['new_status']);

                        /** @var AttendanceService $service */
                        $service = app(AttendanceService::class);

                        $result = $service->adminResolveAttendance(
                            $participant,
                            $newStatus,
                            $admin,
                            $data['override_reason'],
                            false, // allow override without prior dispute
                        );

                        if ($result['success']) {
                            Notification::make()
                                ->title($result['reason'])
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title($result['reason'])
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
