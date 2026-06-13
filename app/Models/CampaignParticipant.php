<?php

namespace App\Models;

use App\Dto\EntityMeta;
use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use Database\Factories\CampaignParticipantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property JoinSource|null $join_source
 * @property ParticipantRole|null $role
 * @property ParticipantStatus|null $status
 * @property Carbon|null $created_at
 * @property Carbon|null $confirmation_expires_at
 * @property Carbon|null $waitlisted_at
 * @property Carbon|null $benched_at
 * @property Carbon|null $removed_at
 */
class CampaignParticipant extends Pivot
{
    /** @use HasFactory<CampaignParticipantFactory> */
    use HasFactory;

    protected $table = 'campaign_participants';

    protected $keyType = 'string';

    protected $fillable = ['campaign_id', 'user_id', 'invitee_email', 'role', 'status', 'benched_at', 'join_source', 'created_at', 'waitlisted_at', 'confirmation_expires_at', 'confirmation_attempts', 'short_link_id', 'removed_by', 'removed_at'];

    protected $casts = [
        'role' => ParticipantRole::class,
        'status' => ParticipantStatus::class,
        'benched_at' => 'datetime',
        'join_source' => JoinSource::class,
        'created_at' => 'datetime',
        'waitlisted_at' => 'datetime',
        'confirmation_expires_at' => 'datetime',
        'confirmation_attempts' => 'integer',
        'short_link_id' => 'integer',
        'removed_at' => 'datetime',
    ];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (self $participant) {
            if (empty($participant->id)) {
                $participant->id = (string) Str::uuid();
            }
            $participant->created_at = $participant->created_at ?? now();
        });
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ShortLink, $this>
     */
    public function shortLink(): BelongsTo
    {
        return $this->belongsTo(ShortLink::class);
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
     * Resolve entity metadata from a participant instance.
     *
     * Centralizes the repeated instanceof checks used in commands, jobs,
     * and services for logging, locking, and querying.
     */
    public static function entityMeta(): EntityMeta
    {
        return EntityMeta::forCampaign();
    }
}
