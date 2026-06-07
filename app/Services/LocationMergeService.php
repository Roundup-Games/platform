<?php

namespace App\Services;

use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LocationMergeService
{
    /**
     * Merge source location into target location.
     *
     * In a DB transaction:
     * 1. Reassign all FK references from source to target via raw UPDATE.
     * 2. Count affected rows per table.
     * 3. Delete the source location.
     * 4. Return counts per table.
     *
     * @param  Location  $source  The location to merge away (will be deleted).
     * @param  Location  $target  The location to merge into (kept).
     * @return array{games: int, events: int, campaigns: int, users: int, source_id: string, target_id: string}
     *
     * @throws \Throwable
     */
    public function merge(Location $source, Location $target, ?User $actedBy = null): array
    {
        if ($source->id === $target->id) {
            throw new \InvalidArgumentException('Cannot merge a location into itself.');
        }

        $sourceId = (string) $source->id;
        $targetId = (string) $target->id;

        $now = now();

        $counts = DB::transaction(function () use ($sourceId, $targetId, $source, $now) {
            $games = DB::table('games')
                ->where('location_id', $sourceId)
                ->update(['location_id' => $targetId, 'updated_at' => $now]);

            $events = DB::table('events')
                ->where('location_id', $sourceId)
                ->update(['location_id' => $targetId, 'updated_at' => $now]);

            $campaigns = DB::table('campaigns')
                ->where('location_id', $sourceId)
                ->update(['location_id' => $targetId, 'updated_at' => $now]);

            $users = DB::table('users')
                ->where('location_id', $sourceId)
                ->update(['location_id' => $targetId, 'updated_at' => $now]);

            $source->delete();

            return [
                'games' => $games,
                'events' => $events,
                'campaigns' => $campaigns,
                'users' => $users,
            ];
        });

        Log::info('Location merge completed', [
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'counts' => $counts,
            'merged_by' => $actedBy?->id ?? auth()->id(),
        ]);

        return [
            'source_id' => $sourceId,
            'target_id' => $targetId,
            ...$counts,
        ];
    }
}
