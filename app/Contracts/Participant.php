<?php

namespace App\Contracts;

use App\Dto\EntityMeta;

/**
 * A participant row belonging to a Game or Campaign.
 *
 * Unifies GameParticipant and CampaignParticipant behind a single seam so
 * participation-lifecycle services accept one type instead of re-deriving the
 * entity type via instanceof in every method.
 *
 * The interface exposes entity metadata only — enough for a service to lock,
 * log, and transition a participant without knowing whether it sits on a game
 * or a campaign. EntityMeta::fromParticipant() routes through this method,
 * removing the instanceof branch that was previously duplicated across
 * BenchService, WaitlistService, and ParticipantService.
 */
interface Participant
{
    /**
     * Metadata describing this participant's parent entity type.
     *
     * Pure type information — does not trigger a database query.
     */
    public function getEntityMeta(): EntityMeta;
}
