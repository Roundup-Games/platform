<?php

namespace App\Jobs;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Review;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\PostHogClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
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
        \App\Models\Game::class,
        \App\Models\Campaign::class,
        \App\Models\GameParticipant::class,
        \App\Models\Review::class,
        \App\Models\User::class,
        \App\Models\UserRelationship::class,
    ];

    /**
     * Resolve the subject model from stored type/id.
     *
     * Expects full FQCN (e.g. App\Models\Game) as set by PostHogEventBridge.
     * Restricted to ALLOWED_SUBJECT_TYPES for defense-in-depth.
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
            return $this->subjectType::find($this->subjectId);
        } catch (\Throwable) {
            // Subject resolution failure is non-critical for enrichment
        }

        return null;
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
                    $set['games_created_count'] = Game::where('owner_id', $user->id)->count();
                    $set['last_game_created_at'] = now()->toIso8601String();
                    $setOnce['first_game_created_at'] = now()->toIso8601String();
                    break;

                case ActivityType::PlayerJoined:
                    $set['games_joined_count'] = GameParticipant::where('user_id', $user->id)->count();
                    break;

                case ActivityType::CampaignCreated:
                    $set['campaigns_created_count'] = Campaign::where('owner_id', $user->id)->count();
                    $setOnce['first_campaign_created_at'] = now()->toIso8601String();
                    break;

                case ActivityType::SessionScheduled:
                    $setOnce['first_session_attended_at'] = now()->toIso8601String();
                    break;

                case ActivityType::SessionRecapped:
                    $setOnce['first_session_recapped_at'] = now()->toIso8601String();
                    break;

                case ActivityType::ReviewReceived:
                    $set['reviews_given_count'] = Review::where('reviewer_id', $user->id)->count();
                    $setOnce['first_review_given_at'] = now()->toIso8601String();
                    break;

                case ActivityType::FollowReceived:
                    $set['following_count'] = UserRelationship::where('user_id', $user->id)
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
            $teams = Team::whereHas('activeMembers', fn ($q) => $q->where('user_id', $user->id))
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
        return ActivityLog::where('user_id', $user->id)
            ->where('event_type', ActivityType::InvitationAccepted->value)
            ->count();
    }
}
