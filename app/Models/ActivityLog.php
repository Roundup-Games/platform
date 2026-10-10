<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Models\Concerns\HasPlatformUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ActivityType|null $event_type
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ActivityLog extends Model
{
    use HasPlatformUuid;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id', 'user_id', 'subject_type', 'subject_id',
        'event_type', 'properties', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'event_type' => ActivityType::class,
            'created_at' => 'datetime',
        ];
    }

    // ── Relationships ──────────────────────────────────

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
