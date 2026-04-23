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

/**
 * Central service for recording and querying activity logs.
 *
 * All write operations are resilient: failures are logged with structured
 * context but never throw, so primary actions (game creation, follows, etc.)
 * are never blocked by activity logging failures.
 */
class ActivityLogService
{
    /**
     * Record an activity log entry for a user.
     *
     * Failures are caught and logged — this method never throws.
     */
    public function log(
        ActivityType $type,
        User $user,
        ?Model $subject = null,
        array $properties = [],
    ): ?ActivityLog {
        try {
            return ActivityLog::create([
                'user_id' => $user->id,
                'subject_type' => $subject ? get_class($subject) : null,
                'subject_id' => $subject?->getKey(),
                'event_type' => $type,
                'properties' => !empty($properties) ? $properties : null,
                'created_at' => now(),
            ]);
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
     */
    public function getRecentForUser(User $user, int $limit = 20): Collection
    {
        return ActivityLog::with('subject')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Log an activity for all participants of a game or campaign.
     *
     * Used for events like game_created / campaign_created where every
     * participant should see the event in their activity feed.
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
            'user_id' => $userId,
            'subject_type' => get_class($subject),
            'subject_id' => $subject->getKey(),
            'event_type' => $type->value,
            'properties' => !empty($properties) ? json_encode($properties) : null,
            'created_at' => $now,
        ], $userIds);

        try {
            // Chunk to avoid exceeding placeholder limits on large participant sets
            collect($rows)->chunk(500)->each(function (Collection $chunk) {
                DB::table('activity_logs')->insert($chunk->all());
            });
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
     * Resolve participant user IDs from a Game or Campaign model.
     *
     * @return string[]
     */
    protected function getParticipantUserIds(Model $subject): array
    {
        if ($subject instanceof Game) {
            return $subject->participants()
                ->pluck('user_id')
                ->map(fn ($id) => (string) $id)
                ->all();
        }

        if ($subject instanceof Campaign) {
            return $subject->participants()
                ->pluck('user_id')
                ->map(fn ($id) => (string) $id)
                ->all();
        }

        return [];
    }
}
