<?php

namespace App\Models;

use App\Enums\VibeFlag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property VibeFlag $vibe_preference_value
 * @property string $preference_type
 */
class UserVibePreference extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    // Eloquent's $primaryKey type is string, but this model uses a composite key.
    // PHPStan correctly flags the type mismatch — this is a known Eloquent limitation.
    protected $primaryKey = ['user_id', 'vibe_preference_value']; // @phpstan-ignore property.defaultValue

    public $table = 'user_vibe_preferences';

    protected $fillable = [
        'user_id',
        'vibe_preference_value',
        'preference_type',
    ];

    protected function casts(): array
    {
        return [
            'vibe_preference_value' => VibeFlag::class,
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
}
