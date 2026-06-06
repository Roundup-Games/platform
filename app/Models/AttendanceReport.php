<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AttendanceReport extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'game_id',
        'reporter_id',
        'reported_id',
        'status',
        'weight_applied',
        'is_corroborated',
        'quarantined',
        'reason',
    ];

    protected $casts = [
        'status' => AttendanceStatus::class,
        'weight_applied' => 'float',
        'is_corroborated' => 'boolean',
        'quarantined' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $report) {
            if (empty($report->id)) {
                $report->id = (string) Str::uuid();
            }
        });
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reported(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_id');
    }
}
