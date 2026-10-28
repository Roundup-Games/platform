<?php

namespace App\Notifications;

use App\Models\SessionZeroSurvey;

/**
 * Shared charter URL resolution for charter lifecycle notifications.
 *
 * Centralizes the charter.view deep link. Used by CharterPublished and
 * CharterAmended, which both expose the public SessionZeroSurvey $charter
 * constructor property this trait reads.
 */
trait RoutesCharter
{
    protected function charterUrl(string $locale): string
    {
        return route('charter.view', ['locale' => $locale, 'uuid' => $this->charter->uuid]);
    }
}
