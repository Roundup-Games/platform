<?php

namespace App\Dto;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * Typed view model for a grouped notification row.
 *
 * Replaces the anonymous stdClass that was created in
 * NotificationQueryService::buildGroups() so PHPStan can verify property access.
 *
 * Properties are mutable because the group is built incrementally during the
 * grouping loop — count, latest, isRead, actorNames, actorIds, and actionUrl
 * are all mutated as notifications are folded into the group.
 */
class NotificationGroup
{
    /**
     * @param  list<string>  $actorNames
     * @param  list<string|null>  $actorIds
     */
    public function __construct(
        public string $type,
        public string $fullType,
        public string $category,
        public int $count,
        public DatabaseNotification $latest,
        public array $actorNames,
        public array $actorIds,
        public bool $isRead,
        public string $groupKey,
        public Carbon $createdAt,
        public ?string $actionUrl,
        public ?string $displayHtml = null,
        public ?string $displayString = null,
    ) {}
}
