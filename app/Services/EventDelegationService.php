<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Models\Event;
use App\Models\User;
use App\Notifications\EventCoOrganizerAdded;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Co-organizer delegation for events (M063/S04).
 *
 * Delegation grants the existing global 'Event Admin' role (RoleSeeder)
 * as an event-scoped assignment: Spatie's teams feature stores the scope
 * on the model_has_roles pivot, so team_id = event id makes the target
 * hold the role's permissions for exactly that one event — the same
 * mechanism ScopedRoleService::hasEventPermission() resolves and the
 * AssignsScopedRoles test fixture exercises.
 *
 * Delegation authority is deliberately stricter than event update: an
 * event-scoped co-organizer passes EventPolicy::update for the event
 * (that is the point of delegation) but must not be able to delegate
 * further — only the organizer or a global admin may grant or revoke
 * co-organizers (access matrix, M063/S04).
 *
 * Every grant and revoke is audit-logged with actor, target user, and
 * event id, following the structured Log::info admin-action convention
 * used by GmRoleService and the Filament admin actions.
 *
 * A NEW grant also notifies the target immediately (D156): the grant
 * itself is silent — no invitation to accept — so the notification is
 * dispatched from this service (the single grant chokepoint) via
 * EventCoOrganizerAdded, meaning every surface notifies exactly once
 * and duplicate grants never re-notify.
 */
class EventDelegationService
{
    private const ROLE_NAME = 'Event Admin';

    public function __construct(private readonly ScopedRoleService $scopedRoles) {}

    /**
     * Grant the event-scoped co-organizer role to the target user.
     *
     * @return bool True when the role was newly assigned; false when the
     *              target already held it (duplicate grant is an
     *              audit-logged no-op, safe for retries and double
     *              clicks). A NEW assignment also stamps the pivot's
     *              granted-at and notifies the target (D156) — duplicate
     *              grants keep the original grant date and never
     *              re-notify.
     *
     * @throws AuthorizationException when the actor cannot update the
     *                                event or lacks delegation authority.
     * @throws InvalidArgumentException when the target is the organizer
     *                                  (ownership is never delegated).
     */
    public function grantCoOrganizer(Event $event, User $target, User $actor): bool
    {
        $this->authorizeDelegation($event, $actor);

        if ((string) $target->id === (string) $event->organizer_id) {
            throw new InvalidArgumentException('The organizer cannot be granted a co-organizer role.');
        }

        $role = $this->resolveRole();

        $newlyAssigned = $this->withEventScope($event, $target, function () use ($target, $role): bool {
            if ($target->hasRole($role)) {
                return false;
            }

            $target->assignRole($role);

            return true;
        });

        Log::info(
            $newlyAssigned ? 'event.co_organizer_granted' : 'event.co_organizer_grant_skipped_duplicate',
            [
                'actor_id' => $actor->id,
                'target_user_id' => $target->id,
                'event_id' => $event->id,
            ],
        );

        if ($newlyAssigned) {
            $this->stampGrantedAt($event, $target, $role);

            app(NotificationService::class)->send(
                $target,
                new EventCoOrganizerAdded($event, $actor),
                NotificationCategory::EventRegistration,
            );
        }

        return $newlyAssigned;
    }

    /**
     * Revoke the event-scoped co-organizer role from the target user.
     *
     * Idempotent: revoking a user who does not hold the role is an
     * audit-logged no-op. The organizer cannot be revoked — their
     * authority comes from events.organizer_id, not a scoped role.
     *
     * @throws AuthorizationException when the actor cannot update the
     *                                event or lacks delegation authority.
     * @throws InvalidArgumentException when the target is the organizer.
     */
    public function revokeCoOrganizer(Event $event, User $target, User $actor): void
    {
        $this->authorizeDelegation($event, $actor);

        if ((string) $target->id === (string) $event->organizer_id) {
            throw new InvalidArgumentException('The organizer cannot be revoked as co-organizer.');
        }

        $role = $this->resolveRole();

        $revoked = $this->withEventScope($event, $target, function () use ($target, $role): bool {
            if (! $target->hasRole($role)) {
                return false;
            }

            $target->removeRole($role);

            return true;
        });

        Log::info(
            $revoked ? 'event.co_organizer_revoked' : 'event.co_organizer_revoke_skipped_not_held',
            [
                'actor_id' => $actor->id,
                'target_user_id' => $target->id,
                'event_id' => $event->id,
            ],
        );
    }

    /**
     * All co-organizers of the event: users holding the event-scoped
     * 'Event Admin' assignment, ordered by name.
     *
     * Queries the pivot directly (not whereHas) so the listing is
     * correct regardless of the caller's current permission-team
     * context — the same approach as ScopedRoleService::doIsGlobalAdmin.
     *
     * Each returned User carries a non-persisted granted_at attribute
     * (Carbon|null) from the pivot row, powering the ManageEvent Team
     * tab's granted-at column. It is null for assignments predating
     * the created_at column.
     *
     * @return Collection<int, User>
     */
    public function coOrganizers(Event $event): Collection
    {
        $assignments = $this->scopedCoOrganizerAssignments($event);

        return User::query()
            ->whereIn('id', $assignments->pluck('model_id'))
            ->orderBy('name')
            ->get()
            ->each(fn (User $user) => $user->setAttribute(
                'granted_at',
                ($createdAt = $assignments->firstWhere('model_id', $user->id)?->created_at) === null
                    ? null
                    : Carbon::parse($createdAt),
            ));
    }

    /**
     * Does the user hold the event-scoped co-organizer assignment?
     *
     * Answers strictly about the scoped assignment — the organizer's
     * authority comes from EventPolicy ownership checks, not this role,
     * and is not reported here.
     */
    public function isCoOrganizer(User $user, Event $event): bool
    {
        $role = Role::query()
            ->where('name', self::ROLE_NAME)
            ->whereNull('team_id')
            ->first();

        if ($role === null) {
            return false;
        }

        return DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('team_id', $event->id)
            ->where('model_type', get_class($user))
            ->where('model_id', $user->id)
            ->exists();
    }

    /**
     * Delegation authority check: the actor must pass EventPolicy::update
     * AND be the organizer or a global admin. An event-scoped
     * co-organizer satisfies the first but not the second, so they can
     * manage the event without being able to delegate further.
     */
    private function authorizeDelegation(Event $event, User $actor): void
    {
        if (! $actor->can('update', $event)) {
            throw new AuthorizationException('You are not allowed to manage this event.');
        }

        $isOrganizer = (string) $actor->id === (string) $event->organizer_id;

        if (! $isOrganizer && ! $this->scopedRoles->isGlobalAdmin($actor)) {
            throw new AuthorizationException('Only the organizer or a global admin can manage co-organizers.');
        }
    }

    /**
     * Run the callback inside the event's Spatie permission-team scope.
     *
     * Mirrors ScopedRoleService's cache discipline: HasRoles caches the
     * roles relation per team context, so cached permissions are
     * forgotten and model relations unloaded on entry, and the original
     * context is guaranteed restored in finally — including on
     * exception.
     *
     * @template TCallbackResult
     *
     * @param  callable(): TCallbackResult  $callback
     * @return TCallbackResult
     */
    private function withEventScope(Event $event, User $target, callable $callback): mixed
    {
        $originalTeamId = getPermissionsTeamId();

        setPermissionsTeamId($event->id);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $target->unsetRelations();

        try {
            return $callback();
        } finally {
            setPermissionsTeamId($originalTeamId);
            app()[PermissionRegistrar::class]->forgetCachedPermissions();
            $target->unsetRelations();
        }
    }

    /**
     * Event-scoped 'Event Admin' pivot rows for the listing.
     *
     * @return Collection<int, object{model_id: string, created_at: string|null}>
     */
    private function scopedCoOrganizerAssignments(Event $event): Collection
    {
        // Raw pivot rows straight from the DB; the column contract is a
        // uuid string key and a nullable string grant timestamp.
        /** @var Collection<int, object{model_id: string, created_at: string|null}> $rows */
        $rows = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', self::ROLE_NAME)
            ->whereNull('roles.team_id')
            ->where('model_has_roles.team_id', $event->id)
            ->where('model_has_roles.model_type', User::class)
            ->get(['model_has_roles.model_id', 'model_has_roles.created_at']);

        return $rows;
    }

    /**
     * Stamp the pivot's created_at (granted-at) on a fresh assignment.
     *
     * Spatie does not timestamp model_has_roles, so the grant date is
     * written explicitly after a NEW assignment only — duplicate grants
     * skip this and keep the original date.
     */
    private function stampGrantedAt(Event $event, User $target, Role $role): void
    {
        DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('team_id', $event->id)
            ->where('model_type', User::class)
            ->where('model_id', $target->id)
            ->update(['created_at' => now()]);
    }

    /**
     * Resolve the global 'Event Admin' role definition.
     *
     * firstOrFail (not firstOrCreate): the role's permission set comes
     * from RoleSeeder, and a silently auto-created role with zero
     * permissions would make delegation look successful while granting
     * nothing — a mis-seeded environment must fail loudly.
     */
    private function resolveRole(): Role
    {
        return Role::query()
            ->where('name', self::ROLE_NAME)
            ->whereNull('team_id')
            ->firstOrFail();
    }
}
