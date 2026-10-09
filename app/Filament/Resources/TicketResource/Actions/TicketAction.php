<?php

namespace App\Filament\Resources\TicketResource\Actions;

use App\Models\User;
use Escalated\Laravel\Models\Ticket;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Shared base for TicketResource admin actions.
 *
 * Owns the cross-cutting concerns every moderation/support action repeated in
 * the former ViewTicket perform* methods: the authenticated-admin guard, the
 * level-9-safe mixed→string coercion for ticket metadata, the platform-admin
 * escalation lookup, and the single canonical failure path (Log::error +
 * danger toast) that replaced the ~15 identical try/catch blocks.
 */
abstract class TicketAction
{
    /**
     * Safely convert a mixed metadata value to a string.
     *
     * Scalar values are stringified; arrays, objects, and other non-scalar
     * values become an empty string. This avoids PHPStan's level-9 rejection
     * of casting `mixed` directly to string.
     */
    protected static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The authenticated admin, or null when the session user is not a User
     * model (guest session, non-user guard). Callers bail out silently,
     * preserving the original guard semantics.
     */
    protected static function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Find another Platform Admin to assign escalation to.
     * Falls back to the current user if no other Platform Admin exists.
     *
     * @return array{admin: User, assigned_name: string}
     */
    protected static function findPlatformAdminForEscalation(User $currentUser): array
    {
        $admin = User::role('Platform Admin')
            ->where('id', '!=', $currentUser->id)
            ->first() ?? $currentUser;

        return [
            'admin' => $admin,
            'assigned_name' => $admin->name,
        ];
    }

    /**
     * The single shared failure path for admin ticket actions: log the error
     * with ticket context, then surface a danger toast carrying the exception
     * message.
     *
     * @param  array<string, mixed>  $extraLogContext  appended after ticket_id/error
     */
    protected static function fail(Ticket $ticket, string $logMessage, string $toastTitle, \Throwable $e, array $extraLogContext = []): void
    {
        Log::error($logMessage, [
            'ticket_id' => $ticket->id,
            'error' => $e->getMessage(),
            ...$extraLogContext,
        ]);

        Notification::make()
            ->danger()
            ->title($toastTitle)
            ->body($e->getMessage())
            ->send();
    }
}
