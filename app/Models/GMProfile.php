<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GMProfile extends Model
{
    use HasFactory;

    protected $table = 'gm_profiles';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'bio',
        'specializations',
        'slug',
        'average_rating',
        'review_count',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'specializations' => 'array',
            'average_rating' => 'decimal:2',
            'review_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $profile) {
            if (empty($profile->id)) {
                $profile->id = (string) Str::uuid();
            }

            if (empty($profile->slug)) {
                $name = $profile->user?->name ?? 'gm';
                $profile->slug = Str::slug($name) . '-' . Str::random(6);
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
