<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
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

    protected $fillable = ['game_id', 'user_id', 'role', 'status', 'attendance_status', 'confirmation_expires_at', 'waitlisted_at', 'benched_at'];

    protected $casts = [
        'status' => ParticipantStatus::class,
        'attendance_status' => AttendanceStatus::class,
        'confirmation_expires_at' => 'datetime',
        'waitlisted_at' => 'datetime',
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

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
