<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Central service for recording and querying activity logs.
 *
 * All write operations are resilient: failures are logged with structured
 * context but never throw, so primary actions (game creation, follows, etc.)
 * are never blocked by activity logging failures.
 *
 * Activity events are automatically forwarded to PostHog via PostHogEventBridge
 * after successful DB writes. PostHog failures are caught and logged separately.
 */
class ActivityLogService
{
    /**
     * Forward an activity event to PostHog after successful DB write.
     *
     * Uses app() resolution for resilience — if the container cannot resolve
     * the bridge, the error is caught and logged without affecting the caller.
     *
     * @param  array<string, mixed>  $properties
     */
    protected function forwardToPostHog(
        ActivityType $type,
        User $user,
        ?Model $subject = null,
        array $properties = [],
    ): void {
        try {
            app(PostHogEventBridge::class)->forwardEvent($type, $user, $subject, $properties);
        } catch (\Throwable $e) {
            Log::warning('PostHog event forwarding failed', [
                'event_type' => $type->value,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record an activity log entry for a user.
     *
     * Failures are caught and logged — this method never throws.
     *
     * @param  array<string, mixed>  $properties
     */
    public function log(
        ActivityType $type,
        User $user,
        ?Model $subject = null,
        array $properties = [],
    ): ?ActivityLog {
        try {
            $entry = $user->activityLogs()->create([
                'subject_type' => $subject ? get_class($subject) : null,
                'subject_id' => $subject?->getKey(),
                'event_type' => $type,
                'properties' => ! empty($properties) ? $properties : null,
                'created_at' => now(),
            ]);

            $this->forwardToPostHog($type, $user, $subject, $properties);

            return $entry;
        } catch (\Throwable $e) {
            Log::warning('Activity log write failed', [
                'event_type' => $type->value,
                'user_id' => $user->id,
                'subject_type' => $subject ? get_class($subject) : null,
                'subject_id' => $subject?->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Return the most recent activity log entries for a user,
     * with subject eager-loaded, ordered newest first.
     *
     * @return Collection<int, ActivityLog>
     */
    public function getRecentForUser(User $user, int $limit = 20): Collection
    {
        return ActivityLog::with('subject')
            ->whereBelongsTo($user)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Log an activity for all participants of a game or campaign.
     *
     * Used for events like game_created / campaign_created where every
     * participant should see the event in their activity feed.
     *
     * @param  array<string, mixed>  $properties
     */
    public function logForParticipants(
        ActivityType $type,
        Model $subject,
        array $properties = [],
    ): void {
        $userIds = $this->getParticipantUserIds($subject);

        if (empty($userIds)) {
            return;
        }

        $now = now();
        $rows = array_map(fn (string $userId) => [
            'id' => (string) Str::orderedUuid(),
            'user_id' => $userId,
            'subject_type' => get_class($subject),
            'subject_id' => $subject->getKey(),
            'event_type' => $type->value,
            'properties' => ! empty($properties) ? json_encode($properties) : null,
            'created_at' => $now,
        ], $userIds);

        try {
            // Chunk to avoid exceeding placeholder limits on large participant sets
            collect($rows)->chunk(500)->each(function (Collection $chunk) {
                DB::table('activity_logs')->insert($chunk->all());
            });

            // Forward to PostHog for the subject owner (the person who triggered the event)
            $this->forwardParticipantEventToPostHog($type, $subject, $properties);
        } catch (\Throwable $e) {
            Log::warning('Bulk activity log write failed', [
                'event_type' => $type->value,
                'subject_type' => get_class($subject),
                'subject_id' => $subject->getKey(),
                'participant_count' => count($userIds),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Forward a participant-scoped event to PostHog for the subject owner.
     *
     * Games and Campaigns have an owner who is the actor behind participant-
     * scoped events (e.g. game_created, campaign_created). We forward only
     * for the owner to avoid duplicate events — the owner is the actor,
     * participants are passive recipients of the log entry.
     *
     * @param  array<string, mixed>  $properties
     */
    protected function forwardParticipantEventToPostHog(
        ActivityType $type,
        Model $subject,
        array $properties = [],
    ): void {
        $owner = null;

        if ($subject instanceof Game || $subject instanceof Campaign) {
            // Eager-load owner to avoid a lazy-load query on every call
            if (! $subject->relationLoaded('owner')) {
                $subject->load('owner');
            }
            $owner = $subject->owner;
        }

        if ($owner) {
            $this->forwardToPostHog($type, $owner, $subject, $properties);
        }
    }

    /**
     * Resolve participant user IDs from a Game or Campaign model.
     *
     * @return string[]
     */
    protected function getParticipantUserIds(Model $subject): array
    {
        if ($subject instanceof Game) {
            return $subject->participants()
                ->pluck('user_id')
                ->map(fn (mixed $id): string => to_string_id($id))
                ->all();
        }

        if ($subject instanceof Campaign) {
            return $subject->participants()
                ->pluck('user_id')
                ->map(fn (mixed $id): string => to_string_id($id))
                ->all();
        }

        return [];
    }
}
