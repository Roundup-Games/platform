<?php

namespace App\Filament\Concerns;

use App\Enums\AttendanceStatus;
use App\Models\GameParticipant;
use App\Services\AttendanceService;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Shared logic for admin attendance override actions in Filament relation managers.
 *
 * Both AttendanceReportsRelationManager and ParticipantsRelationManager expose
 * an "Override Attendance" action. This trait extracts the common form + action
 * handler so bug fixes propagate to both contexts.
 *
 * See Filament docs — loaded by resource pages via OverridesAttendance::class
 *
 * @phpstan-ignore trait.unused
 */
trait OverridesAttendance
{
    /**
     * Build the override action form fields.
     *
     * @return array<int, Component>
     */
    protected function attendanceOverrideFormFields(): array
    {
        return [
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
        ];
    }

    /**
     * Execute the admin attendance override and send a Filament notification.
     */
    protected function executeAttendanceOverride(GameParticipant $participant, array $data): void
    {
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
    }
}
