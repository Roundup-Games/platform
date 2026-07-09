<?php

namespace App\Notifications;

use App\Models\Campaign;

/**
 * Shared routing logic for notifications that support both Game and Campaign entities.
 *
 * Centralizes the repeated instanceof checks and route resolution.
 * Used by EntityCancelled, EntityCompleted, EntityInvitation, EntityUpdated,
 * WaitlistPromoted, ConfirmationExpired, and WaitlistExpiredRejected.
 */
trait RoutesGameOrCampaign
{
    protected function getEntityType(): string
    {
        return $this->entity instanceof Campaign ? 'campaign' : 'game';
    }

    protected function getEntityRoute(string $locale): string
    {
        if ($this->entity instanceof Campaign) {
            return route('campaigns.show', ['locale' => $locale, 'id' => $this->entity]);
        }

        return route('games.show', ['locale' => $locale, 'id' => $this->entity]);
    }
}
