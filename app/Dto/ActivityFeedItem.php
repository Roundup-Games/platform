<?php

namespace App\Dto;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Typed activity item used internally by GameActivityFeedService.
 *
 * Replaces raw (object)[...] casts so PHPStan can resolve property types.
 */
class ActivityFeedItem
{
    /**
     * @param  Collection<int, User>|null  $users  Multiple users (e.g. joined friends)
     * @param  Game|Campaign|null  $entityCampaign  The campaign context for session-scheduled items
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly Game|Campaign $entity,
        public readonly string $entityType,
        public readonly ?User $user,
        public readonly ?Carbon $createdAt = null,
        public readonly Game|Campaign|null $entityCampaign = null,
        public readonly ?Collection $users = null,
    ) {}
}
