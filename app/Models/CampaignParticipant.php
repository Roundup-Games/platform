<?php

namespace App\Models;

use App\Enums\ParticipantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CampaignParticipant extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['campaign_id', 'user_id', 'role', 'status', 'benched_at'];

    protected $casts = [
        'status' => ParticipantStatus::class,
        'benched_at' => 'datetime',
    ];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (self $participant) {
            if (empty($participant->id)) {
                $participant->id = (string) Str::uuid();
            }
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
