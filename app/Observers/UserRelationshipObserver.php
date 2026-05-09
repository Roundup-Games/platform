<?php

namespace App\Observers;

use App\Enums\RelationshipType;
use App\Models\UserRelationship;
use App\Services\DashboardCacheService;

class UserRelationshipObserver
{
    public function __construct(
        private DashboardCacheService $cache,
    ) {}

    public function created(UserRelationship $relationship): void
    {
        if ($relationship->type === RelationshipType::Follow) {
            $this->cache->invalidateForUser((string) $relationship->user_id, ['feed']);
            $this->cache->invalidateForUser((string) $relationship->related_user_id, ['feed']);
        }
    }

    public function deleted(UserRelationship $relationship): void
    {
        if ($relationship->type === RelationshipType::Follow) {
            $this->cache->invalidateForUser((string) $relationship->user_id, ['feed']);
            $this->cache->invalidateForUser((string) $relationship->related_user_id, ['feed']);
        }
    }
}
