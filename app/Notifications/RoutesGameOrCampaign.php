<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Models\Game;

/**
 * Shared routing logic for notifications that support both Game and Campaign entities.
 *
 * Centralizes the repeated instanceof checks and route resolution used by
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
            return route('campaigns.detail', ['locale' => $locale, 'id' => $this->entity->id]);
        }

        return route('games.detail', ['locale' => $locale, 'id' => $this->entity->id]);
    }
}
