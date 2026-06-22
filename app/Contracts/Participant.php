<?php

namespace App\Contracts;

use App\Dto\EntityMeta;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\CampaignParticipant;
use App\Models\GameParticipant;
use Illuminate\Database\Eloquent\Model;

/**
 * A participant row belonging to a Game or Campaign.
 *
 * Unifies GameParticipant and CampaignParticipant behind a single seam so
 * participation-lifecycle services accept one type instead of re-deriving the
 * entity type via instanceof in every method.
 *
 * Both adapters extend Illuminate\Database\Eloquent\Relations\Pivot, so they
 * carry the full Eloquent surface (update/delete/getAttribute/save/etc.). The
 * interface declares only the lifecycle-relevant subset that callers rely on:
 * the shared participant columns and the persistence methods. Entity-specific
 * keys (game_id / campaign_id) stay off the contract and route through
 * {@see getEntityMeta()}.
 *
 * @see GameParticipant
 * @see CampaignParticipant
 */
interface Participant
{
    /**
     * Metadata describing this participant's parent entity type.
     *
     * Pure type information — does not trigger a database query.
     */
    public function getEntityMeta(): EntityMeta;

    /**
     * Persist attribute changes to the database.
     *
     * Mirrors {@see Model::update()} — declared on the contract because lifecycle
     * services transition a participant's status/role through this method.
     *
     * @param  array<string, mixed>  $attributes
     * @return bool
     */
    public function update(array $attributes = []);

    /**
     * Delete the participant row from the database.
     *
     * Mirrors {@see Model::delete()}.
     *
     * @return bool|null
     */
    public function delete();

    /**
     * Get a raw attribute value.
     *
     * Mirrors {@see Model::getAttribute()} — declared on the contract because
     * some lifecycle callers read dynamic columns via this accessor.
     *
     * @return mixed
     */
    public function getAttribute(string $key);

    /**
     * The participant's UUID primary key.
     *
     * Always non-null for persisted participants; lifecycle services only ever
     * receive persisted rows.
     */
    public function getId(): string;

    /**
     * The participating user's UUID, or null for an email-only invitee.
     *
     * Email-only participants (invitee_email set, no user) carry null here —
     * callers that resolve a User model must null-check before User::find().
     */
    public function getUserId(): ?string;

    /**
     * The invitee email for an email-only participant, or null for user-based.
     */
    public function getInviteeEmail(): ?string;

    /**
     * The participant's lifecycle status, or null when not yet persisted.
     */
    public function getStatus(): ?ParticipantStatus;

    /**
     * The participant's role, or null when not yet persisted.
     */
    public function getRole(): ?ParticipantRole;
}
