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

    protected $fillable = ['game_id', 'user_id', 'role', 'status', 'created_at', 'attendance_status', 'attendance_reported_by', 'attendance_reported_at', 'attendance_weight', 'attendance_dispute_reason', 'confirmation_expires_at', 'waitlisted_at', 'confirmation_attempts', 'benched_at'];

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
}
