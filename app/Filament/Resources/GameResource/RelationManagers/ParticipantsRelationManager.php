<?php

namespace App\Filament\Resources\GameResource\RelationManagers;

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantRole;
use App\Services\AttendanceService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ParticipantsRelationManager extends RelationManager
{
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
                        'approved' => 'success',
                        'rejected' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('attendance_status')
                    ->label('Attendance')
                    ->badge()
                    ->color(fn (AttendanceStatus $state): string => match ($state) {
                        AttendanceStatus::Attended => 'success',
                        AttendanceStatus::NoShow => 'danger',
                        AttendanceStatus::Excused => 'info',
                        AttendanceStatus::LateCancel => 'warning',
                        AttendanceStatus::CancelledEarly => 'gray',
                    })
                    ->formatStateUsing(fn (AttendanceStatus $state): string => $state->label())
                    ->default('—')
                    ->toggleable(),
                TextColumn::make('attendance_weight')
                    ->label('Weight')
                    ->numeric(2)
                    ->default('—')
                    ->toggleable(),
                IconColumn::make('attendance_disputed')
                    ->label('Disputed')
                    ->icon(fn ($record) => $record->attendance_disputed_at ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-minus')
                    ->color(fn ($record) => $record->attendance_disputed_at ? 'danger' : 'gray')
                    ->tooltip(fn ($record) => $record->attendance_disputed_at?->format('M j, Y g:i A'))
                    ->toggleable(),
                TextColumn::make('attendance_reported_at')
                    ->label('Reported At')
                    ->dateTime()
                    ->default('—')
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
                        $admin = auth()->user();
                        $newStatus = AttendanceStatus::from($data['new_status']);

                        /** @var AttendanceService $service */
                        $service = app(AttendanceService::class);

                        $result = $service->adminResolveAttendance(
                            $record,
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
