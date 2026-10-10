<?php

namespace App\Models\Concerns;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Shared behaviour for participant pivot models (GameParticipant,
 * CampaignParticipant).
 *
 * Both models use HasPlatformUuid primary keys (D170); this concern keeps
 * only the shared created_at stamping (their tables have no $timestamps)
 * and the source-label accessor that prefers a short link's label and
 * falls back to the JoinSource enum.
 */
trait HasParticipantDefaults
{
    /**
     * Stamp created_at on create (no $timestamps on the pivot tables).
     */
    protected static function bootHasParticipantDefaults(): void
    {
        static::creating(function ($participant) {
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

    /**
     * Entity relationship accessors.
     *
     * The relationship method differs per adapter (game() on GameParticipant,
     * campaign() on CampaignParticipant) so instanceof narrows to the right
     * one. loadMissing hydrates from the DB if the caller didn't eager-load.
     */
    public function getEntity(): Campaign|Game|null
    {
        if ($this instanceof CampaignParticipant) {
            $this->loadMissing('campaign');

            return $this->campaign;
        }

        if ($this instanceof GameParticipant) {
            $this->loadMissing('game');

            return $this->game;
        }

        return null;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getConfirmationExpiresAt(): ?Carbon
    {
        $value = $this->confirmation_expires_at;

        return $value instanceof Carbon ? $value : null;
    }

    public function getWaitlistedAt(): ?Carbon
    {
        $value = $this->waitlisted_at;

        return $value instanceof Carbon ? $value : null;
    }
}
