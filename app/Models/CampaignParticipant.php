<?php

namespace App\Models;

use App\Enums\JoinSource;
use App\Enums\ParticipantStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CampaignParticipant extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['campaign_id', 'user_id', 'invitee_email', 'role', 'status', 'benched_at', 'join_source', 'created_at', 'waitlisted_at', 'confirmation_expires_at', 'confirmation_attempts', 'short_link_id'];

    protected $casts = [
        'status' => ParticipantStatus::class,
        'benched_at' => 'datetime',
        'join_source' => JoinSource::class,
        'created_at' => 'datetime',
        'waitlisted_at' => 'datetime',
        'confirmation_expires_at' => 'datetime',
        'confirmation_attempts' => 'integer',
        'short_link_id' => 'integer',
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

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

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
        if ($this->short_link_id && $this->relationLoaded('shortLink') && $this->shortLink) {
            return $this->shortLink->label ?? $this->shortLink->code;
        }

        return $this->join_source?->label();
    }

    /**
     * Resolve entity metadata from a participant instance.
     *
     * Centralizes the repeated instanceof checks used in commands, jobs,
     * and services for logging, locking, and querying.
     *
     * @return array{type: string, foreignKey: string, entityClass: class-string<Campaign>, participantClass: class-string<self>}
     */
    public static function entityMeta(): array
    {
        return [
            'type' => 'campaign',
            'foreignKey' => 'campaign_id',
            'entityClass' => Campaign::class,
            'participantClass' => self::class,
        ];
    }
}
