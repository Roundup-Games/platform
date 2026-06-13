<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use App\Services\ScopedRoleService;

class EventPolicy
{
    /**
     * Global admin bypass.
     */
    public function before(User $user, string $ability): ?bool
    {
        if (app(ScopedRoleService::class)->isGlobalAdmin($user)) {
            return true;
        }

        return null;
    }

    /**
     * View any event (Filament resource listing).
     */
    public function viewAny(User $user): bool
    {
        return app(ScopedRoleService::class)->hasPermissionInAnyScope($user, 'view event');
    }

    /**
     * View an event.
     */
    public function view(?User $user, Event $event): bool
    {
        // Public events are visible to everyone
        if ($event->is_public) {
            return true;
        }

        // Non-public events require authentication
        if ($user === null) {
            return false;
        }

        // Organizers can always view their own events
        if ((string) $event->organizer_id === (string) $user->id) {
            return true;
        }

        // Check event-scoped permission (Event Admin)
        return app(ScopedRoleService::class)->hasEventPermission($user, 'view event', $event);
    }

    /**
     * Create an event.
     */
    public function create(User $user): bool
    {
        return $this->checkPermission($user, 'create event');
    }

    /**
     * Update an event.
     */
    public function update(User $user, Event $event): bool
    {
        // Organizer can always update their own event
        if ((string) $event->organizer_id === (string) $user->id) {
            return true;
        }

        // Check event-scoped permission
        return app(ScopedRoleService::class)->hasEventPermission($user, 'update event', $event);
    }

    /**
     * Delete an event.
     */
    public function delete(User $user, Event $event): bool
    {
        // Organizer can delete their own event
        if ((string) $event->organizer_id === (string) $user->id) {
            return true;
        }

        return app(ScopedRoleService::class)->hasEventPermission($user, 'delete event', $event);
    }

    /**
     * Check permission without throwing on missing permission.
     */
    private function checkPermission(User $user, string $permission): bool
    {
        return app(ScopedRoleService::class)->checkPermission($user, $permission);
    }
}
