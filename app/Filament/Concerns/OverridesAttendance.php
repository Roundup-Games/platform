<?php

namespace App\Filament\Concerns;

use App\Enums\AttendanceStatus;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\AttendanceService;
use Filament\Forms\Components\Field;
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
     * Narrow a mixed value to a non-empty string (level-9 safe).
     */
    private static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Build the override action form fields.
     *
     * @return array<int, Field>
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
     *
     * @param  array<string, mixed>  $data
     */
    protected function executeAttendanceOverride(GameParticipant $participant, array $data): void
    {
        $admin = auth()->user();
        if (! $admin instanceof User) {
            return;
        }
        $newStatus = AttendanceStatus::from(self::asString($data['new_status']));

        /** @var AttendanceService $service */
        $service = app(AttendanceService::class);

        $result = $service->adminResolveAttendance(
            $participant,
            $newStatus,
            $admin,
            self::asString($data['override_reason']),
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
