<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Jobs\EnrichPostHogProfile;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Bridges ActivityLogService events to PostHog for analytics.
 *
 * Maps ActivityType enums to PostHog event names and enriches them with
 * subject-specific properties that enable funnels, retention analysis,
 * and conversion tracking.
 *
 * Event capture happens inline (fast SDK call). User profile and team
 * group enrichment is dispatched to a queued job to avoid DB count
 * queries blocking the request lifecycle.
 *
 * All PostHog calls are wrapped in try/catch — analytics failures never
 * propagate to the calling code. Consistent with ActivityLogService pattern.
 */
class PostHogEventBridge
{
    /**
     * Activity types that produce meaningful profile enrichment.
     *
     * Only these types dispatch EnrichPostHogProfile — the rest have
     * no enrichment logic in the job, so dispatching them wastes a
     * queue slot, a DB query (User::find), and a PostHog identify call.
     */
    private const ENRICHED_TYPES = [
        ActivityType::GameCreated,
        ActivityType::PlayerJoined,
        ActivityType::CampaignCreated,
        ActivityType::SessionScheduled,
        ActivityType::SessionRecapped,
        ActivityType::ReviewReceived,
        ActivityType::FollowReceived,
        ActivityType::InvitationAccepted,
    ];

    public function __construct(
        private readonly PostHogClient $posthog,
    ) {}

    /**
     * Forward an activity event to PostHog with enriched properties.
     *
     * Failures are caught and logged — this method never throws.
     */
    public function forwardEvent(
        ActivityType $type,
        User $user,
        ?Model $subject = null,
        array $properties = [],
    ): void {
        if (! $this->posthog->isEnabled()) {
            return;
        }

        try {
            $eventName = $this->resolveEventName($type);
            $eventProperties = array_merge(
                $this->extractProperties($type, $subject),
                $properties,
            );

            $this->posthog->capture([
                'distinctId' => (string) $user->id,
                'event' => $eventName,
                'properties' => $eventProperties,
            ]);

            // Dispatch enrichment to queue — DB count queries run async.
            // Only dispatch for event types that have meaningful enrichment
            // to avoid wasting queue slots on no-op jobs.
            if (in_array($type, self::ENRICHED_TYPES, true)) {
                EnrichPostHogProfile::dispatch(
                    $type->value,
                    $user->id,
                    $subject ? get_class($subject) : null,
                    $subject?->getKey(),
                );
            }
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('posthog.event_bridge.failed', [
                'event_type' => $type->value,
                'user_id' => $user->id,
                'subject_type' => $subject ? get_class($subject) : null,
                'subject_id' => $subject?->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map ActivityType to a PostHog event name.
     *
     * Uses a consistent naming convention: namespace.action format
     * that works well with PostHog's event filtering and funnel tools.
     *
     * IMPORTANT: When adding a new ActivityType case, both this method
     * and extractProperties() must be updated. The `default` branch
     * prevents crashes but results in events with no properties and
     * a generic name — not useful for analytics. Add an explicit match
     * arm instead.
     */
    private function resolveEventName(ActivityType $type): string
    {
        return match ($type) {
            ActivityType::GameCreated => 'game.created',
            ActivityType::GameCompleted => 'game.completed',
            ActivityType::GameCanceled => 'game.canceled',
            ActivityType::GameUpdated => 'game.updated',
            ActivityType::CampaignCreated => 'campaign.created',
            ActivityType::CampaignCompleted => 'campaign.completed',
            ActivityType::CampaignCanceled => 'campaign.canceled',
            ActivityType::CampaignUpdated => 'campaign.updated',
            ActivityType::PlayerJoined => 'game.player_joined',
            ActivityType::SessionScheduled => 'session.scheduled',
            ActivityType::InvitationReceived => 'invitation.received',
            ActivityType::InvitationAccepted => 'invitation.accepted',
            ActivityType::ReviewReceived => 'review.received',
            ActivityType::FollowReceived => 'follow.received',
            ActivityType::SessionRecapped => 'session.recapped',
            ActivityType::DebriefingSubmitted => 'session.debriefing_submitted',
            default => 'activity.' . $type->value,
        };
    }

    /**
     * Extract subject-specific properties based on the ActivityType.
     *
     * Each case inspects the subject model for properties that are
     * valuable for analytics funnels and segmentation.
     */
    private function extractProperties(ActivityType $type, ?Model $subject): array
    {
        return match ($type) {
            ActivityType::GameCreated,
            ActivityType::GameCanceled,
            ActivityType::GameCompleted,
            ActivityType::GameUpdated => $this->extractGameProperties($subject),

            ActivityType::CampaignCreated,
            ActivityType::CampaignCompleted,
            ActivityType::CampaignCanceled,
            ActivityType::CampaignUpdated => $this->extractCampaignProperties($subject),

            ActivityType::PlayerJoined => $this->extractPlayerJoinedProperties($subject),

            ActivityType::SessionScheduled => $this->extractSessionScheduledProperties($subject),

            ActivityType::InvitationReceived,
            ActivityType::InvitationAccepted => $this->extractInvitationProperties($subject, $type),

            ActivityType::ReviewReceived => $this->extractReviewProperties($subject),

            ActivityType::FollowReceived => $this->extractFollowProperties($subject),

            // Events without subject-specific enrichment
            ActivityType::SessionRecapped,
            ActivityType::DebriefingSubmitted => [],

            // Future ActivityType cases get forwarded without enrichment
            default => [],
        };
    }

    /**
     * Game properties: game_system, visibility, max_players, location_type, is_online.
     */
    private function extractGameProperties(?Model $subject): array
    {
        if (! $subject instanceof Game) {
            return [];
        }

        $subject->loadMissing('gameSystem');
        $gameSystem = $subject->gameSystem;

        return [
            'game_id' => $subject->id,
            'game_system' => $gameSystem?->name,
            'game_system_id' => $subject->game_system_id,
            'visibility' => $subject->visibility?->value,
            'max_players' => $subject->max_players,
            'min_players' => $subject->min_players,
            'location_type' => $subject->location['type'] ?? null,
            'is_online' => ($subject->location['type'] ?? null) === 'online',
            'game_type' => $subject->game_type?->value,
        ];
    }

    /**
     * Campaign properties: game_system, visibility, max_players.
     */
    private function extractCampaignProperties(?Model $subject): array
    {
        if (! $subject instanceof Campaign) {
            return [];
        }

        $subject->loadMissing('gameSystem');
        $gameSystem = $subject->gameSystem;

        return [
            'campaign_id' => $subject->id,
            'game_system' => $gameSystem?->name,
            'game_system_id' => $subject->game_system_id,
            'visibility' => $subject->visibility?->value,
            'max_players' => $subject->max_players,
            'min_players' => $subject->min_players,
        ];
    }

    /**
     * Player joined properties: game_system, participant_role, source.
     *
     * Expects a GameParticipant subject for full enrichment (role, source).
     * Falls back to Game subject properties when a Game is passed directly.
     * Callers should pass GameParticipant for PlayerJoined events.
     */
    private function extractPlayerJoinedProperties(?Model $subject): array
    {
        if ($subject instanceof GameParticipant) {
            $subject->loadMissing('game.gameSystem');
            $game = $subject->game;
            $gameSystem = $game?->gameSystem;

            return [
                'game_id' => $subject->game_id,
                'game_system' => $gameSystem?->name,
                'participant_role' => $subject->role,
                'source' => $subject->join_source?->value,
            ];
        }

        // Fallback: subject might be the Game itself
        if ($subject instanceof Game) {
            return $this->extractGameProperties($subject);
        }

        return [];
    }

    /**
     * Session scheduled properties: game_system, scheduled_date, location_type.
     */
    private function extractSessionScheduledProperties(?Model $subject): array
    {
        if (! $subject instanceof Game) {
            return [];
        }

        $subject->loadMissing('gameSystem');
        $gameSystem = $subject->gameSystem;

        return [
            'game_id' => $subject->id,
            'game_system' => $gameSystem?->name,
            'scheduled_date' => $subject->date_time?->toDateString(),
            'location_type' => $subject->location['type'] ?? null,
            'is_online' => ($subject->location['type'] ?? null) === 'online',
        ];
    }

    /**
     * Invitation properties: source_type (game/campaign), inviter_id.
     */
    private function extractInvitationProperties(?Model $subject, ActivityType $type): array
    {
        if (! $subject) {
            return [];
        }

        $sourceType = match (true) {
            $subject instanceof Game => 'game',
            $subject instanceof Campaign => 'campaign',
            default => class_basename($subject),
        };

        return [
            'source_type' => $sourceType,
            'source_id' => $subject->getKey(),
            'inviter_id' => $subject instanceof Game || $subject instanceof Campaign
                ? $subject->owner_id
                : null,
        ];
    }

    /**
     * Review properties: rating, game_system.
     */
    private function extractReviewProperties(?Model $subject): array
    {
        if (! $subject instanceof Review) {
            return [];
        }

        $subject->loadMissing('reviewable.gameSystem');
        $reviewable = $subject->reviewable;
        $gameSystem = null;

        if ($reviewable instanceof Game) {
            $gameSystem = $reviewable->gameSystem?->name;
        } elseif ($reviewable instanceof Campaign) {
            $gameSystem = $reviewable->gameSystem?->name;
        }

        return [
            'review_id' => $subject->id,
            'rating' => $subject->rating,
            'game_system' => $gameSystem,
            'reviewable_type' => $subject->reviewable_type
                ? class_basename($subject->reviewable_type)
                : null,
        ];
    }

    /**
     * Follow properties: followed user ID only.
     * Follower count is offloaded to the EnrichPostHogProfile job to
     * avoid an inline DB query on every follow event.
     */
    private function extractFollowProperties(?Model $subject): array
    {
        if (! $subject instanceof User) {
            return [];
        }

        return [
            'followed_user_id' => $subject->id,
        ];
    }
}
