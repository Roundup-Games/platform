<?php

namespace App\Models\Concerns;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
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

    /**
     * Typed accessors for the shared participant columns.
     *
     * Exposed on the App\Contracts\Participant interface so lifecycle services
     * read typed values instead of dynamic Eloquent properties (which PHPStan
     * cannot resolve on the interface type). Implemented once here and shared
     * by both pivot models. Larastan resolves these properties from the
     * composing models' migrations/casts, so direct access carries the true
     * column type — no mixed-cast noise.
     */
    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getUserId(): ?string
    {
        $userId = $this->user_id;

        return is_string($userId) ? $userId : null;
    }

    public function getInviteeEmail(): ?string
    {
        $email = $this->invitee_email;

        return is_string($email) ? $email : null;
    }

    public function getStatus(): ?ParticipantStatus
    {
        $status = $this->status;

        return $status instanceof ParticipantStatus ? $status : null;
    }

    public function getRole(): ?ParticipantRole
    {
        $role = $this->role;

        return $role instanceof ParticipantRole ? $role : null;
    }
}
