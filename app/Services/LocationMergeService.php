<?php

namespace App\Services;

use App\Models\Location;
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
    public function merge(Location $source, Location $target): array
    {
        $sourceId = (string) $source->id;
        $targetId = (string) $target->id;

        $counts = DB::transaction(function () use ($sourceId, $targetId, $source) {
            $games = DB::table('games')
                ->where('location_id', $sourceId)
                ->update(['location_id' => $targetId]);

            $events = DB::table('events')
                ->where('location_id', $sourceId)
                ->update(['location_id' => $targetId]);

            $campaigns = DB::table('campaigns')
                ->where('location_id', $sourceId)
                ->update(['location_id' => $targetId]);

            $users = DB::table('users')
                ->where('location_id', $sourceId)
                ->update(['location_id' => $targetId]);

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
            'merged_by' => auth()->id(),
        ]);

        return [
            'source_id' => $sourceId,
            'target_id' => $targetId,
            ...$counts,
        ];
    }
}
