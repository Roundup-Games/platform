<?php

namespace App\Models;

use App\Enums\VibeFlag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserVibePreference extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = ['user_id', 'vibe_preference_value'];
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
