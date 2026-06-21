<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Shared behaviour for participant pivot models (GameParticipant,
 * CampaignParticipant).
 *
 * Both models use UUID primary keys set manually on creating (their tables
 * have no auto-incrementing PK), and both expose a source-label accessor
 * that prefers a short link's label and falls back to the JoinSource enum.
 * Those two pieces were duplicated byte-for-byte across the two models.
 */
trait HasParticipantDefaults
{
    /**
     * Assign a UUID on create and stamp created_at (no $timestamps).
     */
    protected static function bootHasParticipantDefaults(): void
    {
        static::creating(function ($participant) {
            if (empty($participant->id)) {
                $participant->id = (string) Str::uuid();
            }
            $participant->created_at = $participant->created_at ?? now();
        });
    }

    /**
     * Get a human-readable label for the participant's join source.
     *
     * Returns the short link label if one exists, otherwise falls back
     * to the JoinSource enum label.
     */
    public function getSourceLabelAttribute(): ?string
    {
        if ($this->short_link_id) {
            $shortLink = $this->shortLink;
            if ($shortLink) {
                return $shortLink->label ?? $shortLink->code;
            }
        }

        return $this->join_source?->label();
    }
}
