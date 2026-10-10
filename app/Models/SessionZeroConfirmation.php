<?php

namespace App\Models;

use App\Models\Concerns\HasPlatformUuid;
use Database\Factories\SessionZeroConfirmationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $user_id
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SessionZeroConfirmation extends Model
{
    /** @use HasFactory<SessionZeroConfirmationFactory> */
    use HasFactory;

    use HasPlatformUuid;

    protected $table = 'session_zero_confirmations';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'session_zero_survey_id',
        'user_id',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $confirmation) {
            if (empty($confirmation->confirmed_at)) {
                $confirmation->confirmed_at = now();
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    /** @return BelongsTo<SessionZeroSurvey, $this> */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(SessionZeroSurvey::class, 'session_zero_survey_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
