<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use App\Services\ScopedRoleService;

/**
 * Event authorization and the management-tooling scoping model
 * (M063/S04/T03 audit).
 *
 * Two surfaces, two audiences:
 *
 * - Filament EventResource (/admin/events) is GLOBAL-ADMIN ONLY
 *   (EventResource::canAccess → ScopedRoleService::isGlobalAdmin). The
 *   former any-scope viewAny leak is closed: an event-scoped
 *   co-organizer must never reach the full events listing.
 * - Co-organizers manage their single event through the Livewire
 *   surface (ManageEvent / ManageRegistrations / EventAnnouncements),
 *   authorized per-event by update()/delete() below.
 *
 * The 'Event Admin' role (RoleSeeder) is ONE shared Spatie role row;
 * the assignment scope on model_has_roles.team_id decides what it
 * grants:
 *
 * - GLOBAL assignment (team_id=null) = curated organizers (D154):
 *   'create event' + 'view event' (+ dashboard/reads). They manage the
 *   events they organize via organizer_id ownership, not via role
 *   permissions.
 * - EVENT-SCOPED assignment (team_id=event id, granted by
 *   EventDelegationService) = co-organizers with management of exactly
 *   ONE event.
 *
 * Because Spatie resolves permissions from the shared role row, the
 * update/delete half of the split is enforced HERE, not in the seeder:
 * update()/delete() resolve 'update event'/'delete event' via
 * ScopedRoleService::hasEventScopedPermission() only — never the
 * global context — so a global 'Event Admin' assignment can never
 * manage all events. The three management paths that remain:
 * organizer ownership, event-scoped delegation, and the before()
 * global-admin bypass.
 */
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
     * View the full event listing (Filament resource index).
     *
     * Global-context 'view event' holders only — deliberately NOT
     * hasPermissionInAnyScope(): an event-scoped co-organizer holds
     * 'view event' for their event and must not pass viewAny, which
     * would expose every event in the listing (any-scope leak,
     * M063/S04/T03). EventResource::canAccess additionally restricts
     * the whole Filament resource to global admins.
     */
    public function viewAny(User $user): bool
    {
        return $this->checkPermission($user, 'view event');
    }

    /**
     * View an event.
     *
     * The global-context bypass here is intentional: globally-assigned
     * 'Event Admin' (curated organizers) keeps global view per the
     * RoleSeeder split.
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
     * Update an event: organizer ownership or an event-scoped
     * co-organizer assignment only.
     *
     * Deliberately NOT hasEventPermission(): its global-context bypass
     * would let a globally-assigned 'Event Admin' update every event,
     * contradicting the RoleSeeder split (global = create+view only,
     * M063/S04/T03).
     */
    public function update(User $user, Event $event): bool
    {
        // Organizer can always update their own event
        if ((string) $event->organizer_id === (string) $user->id) {
            return true;
        }

        // Check event-scoped permission only (no global bypass)
        return app(ScopedRoleService::class)->hasEventScopedPermission($user, 'update event', $event);
    }

    /**
     * Delete an event: organizer ownership or an event-scoped
     * co-organizer assignment only (see update() — same split).
     */
    public function delete(User $user, Event $event): bool
    {
        // Organizer can delete their own event
        if ((string) $event->organizer_id === (string) $user->id) {
            return true;
        }

        return app(ScopedRoleService::class)->hasEventScopedPermission($user, 'delete event', $event);
    }

    /**
     * Check permission without throwing on missing permission.
     */
    private function checkPermission(User $user, string $permission): bool
    {
        return app(ScopedRoleService::class)->checkPermission($user, $permission);
    }
}
