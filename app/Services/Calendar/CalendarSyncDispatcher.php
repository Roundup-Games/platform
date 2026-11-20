<?php

namespace App\Services\Calendar;

use App\Enums\CalendarSyncOperation;
use App\Enums\ParticipantStatus;
use App\Http\Controllers\ICalFeedController;
use App\Jobs\SyncCalendarEventForUser;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The managed-sync trigger seam (M083/S03-T03): every sanctioned write
 * path that changes what belongs on a user's calendar dispatches through
 * here, never straight onto the queue.
 *
 * ZERO OVERHEAD FOR UNCONNECTED USERS is a design requirement (most users
 * never connect): the entry points run one indexed existence check
 * (google_calendar_connections is unique on user_id) and return before
 * anything else resolves when the user has no active connection.
 *
 * NO PARTICIPANT WRITES EVER (INV-15/K010): this class reads participant
 * rows and pushes queue jobs — nothing else. The sanctioned services keep
 * owning every participant row/status write, so hooking their commit
 * sites cannot trip the participant-write allowlist.
 *
 * Feed semantics are the contract (D287): the iCal feed is the single
 * source of truth for "what belongs on this user's calendar", so every
 * audience computation and window bound here mirrors the feed exactly —
 * hosts, approved game participants, approved campaign members (sessions
 * land on every member's calendar even when their per-session row is
 * benched or waitlisted), confirmed event registrants, and the rolling
 * today-through-+90-day window. Anything this seam misses is healed by
 * the periodic reconcile sweep; its drift counts are the operational
 * signal that a write path lost its trigger.
 *
 * All failures are absorbed here with a structured log: the calendar
 * mirror is best-effort at trigger time (at-least-once + sweep healing)
 * and must never break the participant/notification flow that triggered
 * it.
 */
class CalendarSyncDispatcher
{
    /**
     * Chunk size for fan-out audience queries — one job per user, bounded
     * memory for large campaigns.
     */
    private const int FAN_OUT_CHUNK = 100;

    /**
     * Queue one sync operation for a single user — the core entry point
     * every other method funnels into.
     *
     * No-ops instantly when the user has no active connection, or when the
     * connection's source_toggles disable the entity's source (dispatch
     * gate; GoogleCalendarSyncService re-gates as defense-in-depth).
     */
    public static function dispatch(User $user, Game|Event $entity, CalendarSyncOperation $operation): void
    {
        self::guard(function () use ($user, $entity, $operation): void {
            $connection = self::activeConnection($user);

            if ($connection === null || ! self::sourceEnabled($connection, $entity)) {
                return;
            }

            if (! self::upsertWithinWindow($entity, $operation)) {
                return;
            }

            self::queue($user->id, $entity, $operation);
        });
    }

    /**
     * A participant reached APPROVED on a game or campaign (join with
     * room, application approval, invitation acceptance, bench/waitlist
     * promotion): the entity's occurrences belong on their calendar now.
     *
     * Campaign membership is a standing commitment to the campaign's
     * SCHEDULE (D287): approval fans out to the campaign's sessions
     * inside the feed window — one job per session, past or beyond-+90d
     * sessions are never synced because the feed does not publish them.
     */
    public static function upsertOnJoin(User $user, Game|Campaign $entity): void
    {
        self::guard(function () use ($user, $entity): void {
            if ($entity instanceof Game) {
                self::dispatch($user, $entity, CalendarSyncOperation::Upsert);

                return;
            }

            $connection = self::activeConnection($user);

            if ($connection === null) {
                return;
            }

            self::campaignSessions($entity)->chunkById(self::FAN_OUT_CHUNK, function ($sessions) use ($user, $connection): void {
                foreach ($sessions as $session) {
                    if (! self::sourceEnabled($connection, $session)) {
                        continue;
                    }

                    self::queue($user->id, $session, CalendarSyncOperation::Upsert);
                }
            });
        });
    }

    /**
     * An APPROVED participant left (or was removed from) a game or
     * campaign: the occurrences drop off their calendar (D299 real
     * deletion, not a cancelled patch).
     *
     * Entitlement is re-checked per session for campaign departures: a
     * departing campaign member who still hosts a session or holds an
     * approved per-session participant row keeps that session — exactly
     * the feed's audience rule, so a delete never removes an occurrence
     * the feed still publishes (that would fight the reconcile sweep).
     */
    public static function deleteOnDeparture(User $user, Game|Campaign $entity): void
    {
        self::guard(function () use ($user, $entity): void {
            if ($entity instanceof Game) {
                $campaign = $entity->campaign_id !== null ? Campaign::find($entity->campaign_id) : null;

                if ($campaign !== null && self::isApprovedCampaignMember($user, $campaign)) {
                    // A campaign member leaving a session roster keeps the
                    // session on their calendar via their membership.
                    return;
                }

                self::dispatch($user, $entity, CalendarSyncOperation::Delete);

                return;
            }

            $connection = self::activeConnection($user);

            if ($connection === null) {
                return;
            }

            self::campaignSessions($entity)->chunkById(self::FAN_OUT_CHUNK, function ($sessions) use ($user, $connection): void {
                $sessions = $sessions->filter(
                    fn (Game $session): bool => $session->owner_id !== $user->id
                        && ! self::isApprovedSessionParticipant($user, $session)
                );

                foreach ($sessions as $session) {
                    if (! self::sourceEnabled($connection, $session)) {
                        continue;
                    }

                    self::queue($user->id, $session, CalendarSyncOperation::Delete);
                }
            });
        });
    }

    /**
     * Fan an operation out to every connected user whose calendar carries
     * a GAME per the feed's audience rule — the host, approved game
     * participants, and (for a campaign session) approved members of the
     * campaign. One queued job per user, chunked.
     *
     * Out-of-window occurrences are skipped: the feed does not publish
     * them, so upserting would mint ghosts the sweep deletes, and
     * cancelling them patches events that never existed.
     */
    public static function dispatchToRoster(Game $game, CalendarSyncOperation $operation): void
    {
        self::guard(function () use ($game, $operation): void {
            if (! self::withinFeedWindow($game->date_time)) {
                return;
            }

            $candidateIds = [$game->owner_id];

            foreach (
                GameParticipant::query()
                    ->whereBelongsTo($game)
                    ->where('status', ParticipantStatus::Approved->value)
                    ->pluck('user_id') as $userId
            ) {
                if (is_string($userId)) {
                    $candidateIds[] = $userId;
                }
            }

            if ($game->campaign_id !== null) {
                foreach (
                    CampaignParticipant::query()
                        ->where('campaign_id', $game->campaign_id)
                        ->where('status', ParticipantStatus::Approved->value)
                        ->pluck('user_id') as $userId
                ) {
                    if (is_string($userId)) {
                        $candidateIds[] = $userId;
                    }
                }
            }

            self::queueForUsers(array_values(array_unique($candidateIds)), $game, $operation);
        });
    }

    /**
     * Fan an operation out to every connected user holding a CONFIRMED
     * registration for an event — the feed's registrant rule (pending
     * registrations are not calendar commitments).
     */
    public static function dispatchToEventRegistrants(Event $event, CalendarSyncOperation $operation): void
    {
        self::guard(function () use ($event, $operation): void {
            $candidateIds = [];

            foreach (
                EventRegistration::query()
                    ->whereBelongsTo($event)
                    ->where('status', 'confirmed')
                    ->pluck('user_id') as $userId
            ) {
                if (is_string($userId)) {
                    $candidateIds[] = $userId;
                }
            }

            self::queueForUsers(array_values(array_unique($candidateIds)), $event, $operation);
        });
    }

    // ── Shared plumbing ──────────────────────────────────

    /**
     * The user's live consent, or null — one indexed lookup on the
     * unique-per-user row (reconnect replaces the row, S02-T02).
     */
    private static function activeConnection(User $user): ?GoogleCalendarConnection
    {
        /** @var GoogleCalendarConnection|null */
        return GoogleCalendarConnection::query()
            ->whereBelongsTo($user)
            ->where('status', GoogleCalendarConnection::STATUS_ACTIVE)
            ->first();
    }

    /**
     * Whether the connection's source_toggles enable the entity's source
     * — the mapping GoogleCalendarSyncService re-gates on (one definition
     * on the connection model).
     */
    private static function sourceEnabled(GoogleCalendarConnection $connection, Game|Event $entity): bool
    {
        return $connection->source_toggles[GoogleCalendarConnection::sourceForEntity($entity)] ?? false;
    }

    /**
     * Resolve + queue for every candidate user who actually has a live
     * connection with the entity's source enabled.
     *
     * @param  list<string>  $userIds
     */
    private static function queueForUsers(array $userIds, Game|Event $entity, CalendarSyncOperation $operation): void
    {
        if ($userIds === []) {
            return;
        }

        if (! self::upsertWithinWindow($entity, $operation)) {
            return;
        }

        GoogleCalendarConnection::query()
            ->where('status', GoogleCalendarConnection::STATUS_ACTIVE)
            ->whereIn('user_id', $userIds)
            ->chunkById(self::FAN_OUT_CHUNK, function ($connections) use ($entity, $operation): void {
                foreach ($connections as $connection) {
                    if (! self::sourceEnabled($connection, $entity)) {
                        continue;
                    }

                    self::queue($connection->user_id, $entity, $operation);
                }
            });
    }

    private static function queue(string $userId, Game|Event $entity, CalendarSyncOperation $operation): void
    {
        $entityUuid = $entity->getKey();
        assert(is_string($entityUuid));

        SyncCalendarEventForUser::dispatch(
            $userId,
            $entity instanceof Event
                ? SyncCalendarEventForUser::ENTITY_TYPE_EVENT
                : SyncCalendarEventForUser::ENTITY_TYPE_GAME,
            $entityUuid,
            $operation,
        );
    }

    /**
     * The campaign's sessions inside the feed's rolling window — the same
     * bounds ICalFeedController applies to games (today's community
     * midnight through day +90 inclusive, the +1 day for day +90's
     * sessions).
     *
     * @return Builder<Game>
     */
    private static function campaignSessions(Campaign $campaign): Builder
    {
        [$windowStart, $windowEnd] = ICalFeedController::feedWindow();

        return Game::query()
            ->whereBelongsTo($campaign)
            ->whereBetween('date_time', [$windowStart, $windowEnd->copy()->addDay()->utc()]);
    }

    /**
     * Whether an occurrence falls inside the feed's rolling window (null
     * dates never sync — an unschedulable entity is not a calendar
     * occurrence).
     */
    private static function withinFeedWindow(?Carbon $date): bool
    {
        if ($date === null) {
            return false;
        }

        [$windowStart, $windowEnd] = ICalFeedController::feedWindow();

        return $date->gte($windowStart)
            && $date->lte($windowEnd->copy()->addDay()->utc());
    }

    /**
     * The feed-window gate for queued UPSERTS — the mirror never mints
     * what the feed does not publish. DesiredCalendarSet (the sweep's
     * truth) is window-bounded and the ghost side has no upper bound, so
     * an out-of-window upsert would insert an event the next sweep
     * deletes: appear, vanish ≤ one cadence, reappear months later when
     * the occurrence rolls into the window — incoherent churn the drift
     * counters would misread as a lost trigger. Cancel/Delete stay
     * ungated: the cleanup paths 404-tolerate targets that were never
     * minted.
     *
     * Events compare on the community wall calendar exactly like
     * DesiredCalendarSet::registeredEventsForUser (date column, not an
     * instant) — the same bounds, so a registration the sweep would
     * upsert later is exactly a registration the trigger upserts now.
     */
    private static function upsertWithinWindow(Game|Event $entity, CalendarSyncOperation $operation): bool
    {
        if ($operation !== CalendarSyncOperation::Upsert) {
            return true;
        }

        if ($entity instanceof Event) {
            if ($entity->start_date === null) {
                return false;
            }

            [, $windowEnd] = ICalFeedController::feedWindow();

            $start = $entity->start_date->toDateString();

            return $start >= community_now()->toDateString()
                && $start <= $windowEnd->toDateString();
        }

        return self::withinFeedWindow($entity->date_time);
    }

    private static function isApprovedCampaignMember(User $user, Campaign $campaign): bool
    {
        return CampaignParticipant::query()
            ->whereBelongsTo($campaign)
            ->whereBelongsTo($user)
            ->where('status', ParticipantStatus::Approved->value)
            ->exists();
    }

    private static function isApprovedSessionParticipant(User $user, Game $session): bool
    {
        return GameParticipant::query()
            ->whereBelongsTo($session)
            ->whereBelongsTo($user)
            ->where('status', ParticipantStatus::Approved->value)
            ->exists();
    }

    /**
     * Absorb every dispatch-path failure: the calendar mirror is
     * best-effort (at-least-once, sweep-healed) and must never break the
     * participant flow that triggered it. A queue outage surfaces here as
     * calendar_sync.dispatch_failed and is healed by the reconcile sweep.
     *
     * @param  callable(): void  $body
     */
    private static function guard(callable $body): void
    {
        try {
            $body();
        } catch (Throwable $e) {
            Log::error('calendar_sync.dispatch_failed', [
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
        }
    }
}
