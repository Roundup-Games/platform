<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GameParticipant extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['game_id', 'user_id', 'invitee_email', 'role', 'status', 'created_at', 'attendance_status', 'attendance_reported_by', 'attendance_reported_at', 'attendance_weight', 'attendance_dispute_reason', 'confirmation_expires_at', 'waitlisted_at', 'confirmation_attempts', 'benched_at', 'join_source', 'short_link_id'];

    protected $casts = [
        'status' => ParticipantStatus::class,
        'created_at' => 'datetime',
        'attendance_status' => AttendanceStatus::class,
        'attendance_reported_at' => 'datetime',
        'attendance_weight' => 'float',
        'confirmation_expires_at' => 'datetime',
        'waitlisted_at' => 'datetime',
        'confirmation_attempts' => 'integer',
        'benched_at' => 'datetime',
        'join_source' => JoinSource::class,
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

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendance_reported_by');
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
     *
     * @return array{type: string, foreignKey: string, entityClass: class-string<Game>, participantClass: class-string<self>}
     */
    public static function entityMeta(): array
    {
        return [
            'type' => 'game',
            'foreignKey' => 'game_id',
            'entityClass' => Game::class,
            'participantClass' => self::class,
        ];
    }
}
