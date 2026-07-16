<?php

namespace App\Jobs;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Review;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\PostHogClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Asynchronously enriches PostHog user profiles and team groups.
 *
 * Offloaded from the request lifecycle to avoid DB count queries blocking
 * the primary action (game creation, follow, etc.). The job captures
 * $set/$set_once user properties and team group analytics.
 *
 * Failures are caught and logged — enrichment never blocks the queue worker.
 */
class EnrichPostHogProfile implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Activity types that trigger team group analytics enrichment.
     * Limited to team-relevant events to avoid unnecessary DB queries.
     */
    private const TEAM_ENRICHMENT_TYPES = [
        ActivityType::GameCreated,
        ActivityType::CampaignCreated,
        ActivityType::PlayerJoined,
        ActivityType::SessionScheduled,
    ];

    /**
     * Maximum retries before giving up.
     */
    public int $tries = 2;

    /**
     * Seconds before the job times out.
     */
    public int $timeout = 30;

    /**
     * Drop the job on failure instead of retrying indefinitely.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Store enum value as string for forward-compatible queue serialization.
     * Renaming or removing an ActivityType case won't cause deserialization
     * failures on queued jobs — the job logs a warning and skips.
     *
     * Consent is captured at dispatch time (during the request cycle when
     * the cookie is available) and passed to the job. This avoids needing
     * request context in the queued job handler.
     */
    public function __construct(
        public string $type,
        public string $userId,
        public ?string $subjectType = null,
        public mixed $subjectId = null,
        public bool $hasConsent = true,
    ) {}

    public function handle(PostHogClient $posthog): void
    {
        if (! $posthog->isEnabled()) {
            return;
        }

        // Consent is captured at dispatch time. If consent was not granted
        // when the event was fired, skip enrichment entirely.
        if (! $this->hasConsent) {
            return;
        }

        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        // Resolve enum from stored string value — handles renamed/removed cases gracefully
        try {
            $activityType = ActivityType::from($this->type);
        } catch (\ValueError $e) {
            Log::channel('daily')->warning('posthog.enrichment_job.unknown_type', [
                'type' => $this->type,
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $subject = $this->resolveSubject();

        try {
            $this->enrichUserProfile($posthog, $activityType, $user, $subject);

            if (in_array($activityType, self::TEAM_ENRICHMENT_TYPES, true)) {
                $this->enrichTeamGroup($posthog, $activityType, $user, $subject);
            }
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('posthog.enrichment_job.failed', [
                'event_type' => $this->type,
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Subject types allowed for resolution from queued job payloads.
     *
     * Explicit allowlist prevents arbitrary model instantiation if queue
     * data is ever tampered with (e.g., compromised Redis). Only models
     * that PostHogEventBridge actually dispatches for are permitted.
     */
    private const ALLOWED_SUBJECT_TYPES = [
        Game::class,
        Campaign::class,
        GameParticipant::class,
        Review::class,
        User::class,
        UserRelationship::class,
    ];

    /**
     * Resolve the subject model from stored type/id.
     *
     * Expects full FQCN (e.g. App\Models\Game) as set by PostHogEventBridge.
     * Restricted to ALLOWED_SUBJECT_TYPES for defense-in-depth.
     */
    /**
     * @return (Game|Campaign|GameParticipant|Review|User|UserRelationship)|null
     */
    private function resolveSubject(): ?Model
    {
        if (! $this->subjectType || ! $this->subjectId) {
            return null;
        }

        if (! in_array($this->subjectType, self::ALLOWED_SUBJECT_TYPES, true)) {
            Log::channel('daily')->warning('posthog.enrichment_job.disallowed_subject_type', [
                'subject_type' => $this->subjectType,
            ]);

            return null;
        }

        try {
            $subject = $this->subjectType::findOrFail($this->subjectId);

            return $subject instanceof Model ? $subject : null;
        } catch (ModelNotFoundException) {
            // Subject not found is non-critical for enrichment
        } catch (\Throwable) {
            // Subject resolution failure is non-critical for enrichment
        }

        return null;
    }

    /**
     * Compute non-PII decision-grade person properties from first-party data.
     *
     * These turn raw events into queryable segments in PostHog — the
     * foundational dataset for driving product decisions. All are derived from
     * already-stored first-party data and contain no PII:
     *
     * - modality: online / in_person / mixed tendency, from participation history.
     *   Null for users with <3 resolved attendances (insufficient signal).
     * - primary_game_system: the game system the user has most often attended.
     * - reliability_tier: read from the cached reliability_score column (no query).
     *
     * Two efficient grouped queries; safe to run on every enrichment.
     *
     * @return array<string, string|null>
     */
    private function computeProfileProperties(User $user): array
    {
        try {
            // Modality: online vs in-person participation counts (single grouped query).
            // location is a JSON column; location->>'type' extracts the type key.
            $modality = DB::table('game_participants')
                ->join('games', 'games.id', '=', 'game_participants.game_id')
                ->where('game_participants.user_id', $user->id)
                ->whereNotNull('game_participants.attendance_status')
                ->selectRaw(
                    "COUNT(*) FILTER (WHERE games.location->>'type' = 'online') AS online_count, ".
                    "COUNT(*) FILTER (WHERE games.location->>'type' IS DISTINCT FROM 'online') AS in_person_count"
                )
                ->first();

            $online = (int) ($modality->online_count ?? 0);
            $inPerson = (int) ($modality->in_person_count ?? 0);
            $total = $online + $inPerson;

            $modalityLabel = null;
            if ($total >= 3) {
                $onlineRatio = $online / $total;
                $modalityLabel = match (true) {
                    $onlineRatio >= 0.75 => 'online',
                    $onlineRatio <= 0.25 => 'in_person',
                    default => 'mixed',
                };
            }

            // Primary game system: most-attended across participations.
            // Resolve the ID via a grouped query, then load the model so the
            // name is localized (the raw column is a JSON translatable blob).
            $primarySystemRow = DB::table('game_participants')
                ->join('game_game_system', 'game_game_system.game_id', '=', 'game_participants.game_id')
                ->where('game_participants.user_id', $user->id)
                ->whereNotNull('game_participants.attendance_status')
                ->select('game_game_system.game_system_id')
                ->selectRaw('COUNT(*) AS attended')
                ->groupBy('game_game_system.game_system_id')
                ->orderByDesc('attended')
                ->first();

            $primarySystemName = null;
            if ($primarySystemRow) {
                $primarySystem = GameSystem::find($primarySystemRow->game_system_id);
                $primarySystemName = $primarySystem instanceof GameSystem ? $primarySystem->name : null;
            }

            // Reliability tier: read from the cached JSON column (no extra query).
            // data_get handles array/object/null shapes defensively.
            $tier = is_string(data_get($user->reliability_score, 'tier'))
                ? (string) data_get($user->reliability_score, 'tier')
                : null;

            return array_filter([
                'modality' => $modalityLabel,
                'primary_game_system' => is_string($primarySystemName) ? $primarySystemName : null,
                'reliability_tier' => $tier,
            ]);
        } catch (\Throwable $e) {
            Log::channel('daily')->debug('posthog.profile_properties.compute_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Enrich user profile with $set/$set_once properties.
     */
    private function enrichUserProfile(PostHogClient $posthog, ActivityType $type, User $user, ?Model $subject): void
    {
        $set = ['last_active_at' => now()->toIso8601String()];
        $setOnce = [];

        try {
            switch ($type) {
                case ActivityType::GameCreated:
                    $set['games_created_count'] = Game::whereBelongsTo($user, 'owner')->count();
                    $set['last_game_created_at'] = now()->toIso8601String();
                    $setOnce['first_game_created_at'] = now()->toIso8601String();
                    break;

                case ActivityType::PlayerJoined:
                    $set['games_joined_count'] = GameParticipant::whereBelongsTo($user)->count();
                    // Participation changes modality/cluster tendency — refresh the
                    // computed decision-grade properties (piece 1 of the analytics
                    // foundation). Cheap grouped queries, runs async.
                    $set = array_merge($set, $this->computeProfileProperties($user));
                    break;

                case ActivityType::CampaignCreated:
                    $set['campaigns_created_count'] = Campaign::whereBelongsTo($user, 'owner')->count();
                    $setOnce['first_campaign_created_at'] = now()->toIso8601String();
                    break;

                case ActivityType::SessionScheduled:
                    $setOnce['first_session_attended_at'] = now()->toIso8601String();
                    break;

                case ActivityType::SessionRecapped:
                    $setOnce['first_session_recapped_at'] = now()->toIso8601String();
                    // A recap implies the session resolved — reliability tier may have shifted.
                    $set = array_merge($set, $this->computeProfileProperties($user));
                    break;

                case ActivityType::ReviewReceived:
                    $set['reviews_given_count'] = Review::where('reviewer_id', $user->id)->count();
                    $setOnce['first_review_given_at'] = now()->toIso8601String();
                    break;

                case ActivityType::FollowReceived:
                    $set['following_count'] = UserRelationship::whereBelongsTo($user)
                        ->where('type', 'follow')
                        ->count();
                    break;

                case ActivityType::InvitationAccepted:
                    $set['invitations_accepted_count'] = $this->countInvitationsAccepted($user);
                    $setOnce['first_invitation_accepted_at'] = now()->toIso8601String();
                    break;
            }
        } catch (\Throwable $e) {
            Log::channel('daily')->debug('posthog.user_profile_enrichment.partial_failure', [
                'event_type' => $type->value,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        $posthog->identify([
            'distinctId' => (string) $user->id,
            'properties' => array_filter([
                '$set' => $set,
                '$set_once' => $setOnce,
            ]),
        ]);
    }

    /**
     * Send group analytics for team-related events.
     *
     * Uses a single query with withCount to avoid N+1 between
     * memberships → teams → active member counts.
     */
    private function enrichTeamGroup(PostHogClient $posthog, ActivityType $type, User $user, ?Model $subject): void
    {
        try {
            $gameSystemName = null;
            if ($subject instanceof Game) {
                $gameSystemName = $subject->gameSystem?->name;
            } elseif ($subject instanceof Campaign) {
                $gameSystemName = $subject->gameSystem?->name;
            } elseif ($subject instanceof GameParticipant && $subject->game) {
                $gameSystemName = $subject->game->gameSystem?->name;
            }

            // Single query: fetch all teams where user is an active member,
            // with active member count eager-loaded via withCount.
            $teams = Team::whereHas('activeMembers', fn ($q) => $q->whereBelongsTo($user))
                ->withCount('activeMembers')
                ->get();

            foreach ($teams as $team) {
                $posthog->groupIdentify('team', (string) $team->id, array_filter([
                    'name' => $team->name,
                    'member_count' => $team->active_members_count,
                    'game_system' => $gameSystemName,
                    'city' => $team->city,
                    'country' => $team->country,
                ]));
            }
        } catch (\Throwable $e) {
            Log::channel('daily')->debug('posthog.team_group_enrichment.failed', [
                'event_type' => $type->value,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Count accepted invitations for a user.
     *
     * Relies on the activity_logs_user_event_type_idx composite index
     * for performance. Without it, this degrades to a full table scan.
     */
    private function countInvitationsAccepted(User $user): int
    {
        return ActivityLog::whereBelongsTo($user)
            ->where('event_type', ActivityType::InvitationAccepted->value)
            ->count();
    }

    /**
     * Handle a job failure after all retries exhausted.
     *
     * Enrichment is best-effort — log for ops visibility but
     * do not block the queue worker.
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::channel('daily')->error('posthog.enrichment_job.exhausted_retries', [
            'type' => $this->type,
            'user_id' => $this->userId,
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }
}
