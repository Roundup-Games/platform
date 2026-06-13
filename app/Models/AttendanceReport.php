<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Database\Factories\AttendanceReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int|null $count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AttendanceReport extends Model
{
    /** @use HasFactory<AttendanceReportFactory> */
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

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reported(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_id');
    }
}
