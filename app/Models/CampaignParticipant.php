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

    protected $fillable = ['campaign_id', 'user_id', 'invitee_email', 'role', 'status', 'benched_at', 'join_source', 'created_at', 'waitlisted_at', 'confirmation_expires_at', 'confirmation_attempts'];

    protected $casts = [
        'status' => ParticipantStatus::class,
        'benched_at' => 'datetime',
        'join_source' => JoinSource::class,
        'created_at' => 'datetime',
        'waitlisted_at' => 'datetime',
        'confirmation_expires_at' => 'datetime',
        'confirmation_attempts' => 'integer',
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
}
