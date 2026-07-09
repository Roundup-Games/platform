<?php

namespace App\Models;

use App\Services\ReviewAggregateService;
use Database\Factories\GMProfileFactory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @property string $user_id
 * @property Collection<int, array{name: string, count: int}>|null $top_proficiencies
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GMProfile extends Model
{
    /** @use HasFactory<GMProfileFactory> */
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
                $name = $profile->user->name ?? 'gm';
                $profile->slug = Str::slug($name).'-'.Str::random(6);
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'gm_profile_id');
    }

    /** @return HasMany<SessionZeroSurvey, $this> */
    public function sessionZeroSurveys(): HasMany
    {
        return $this->hasMany(SessionZeroSurvey::class, 'gm_profile_id');
    }

    // ── Convenience Methods ────────────────────────────

    /**
     * Get the average rating, returning null if no reviews exist.
     */
    public function averageRating(): ?float
    {
        return $this->average_rating;
    }

    /**
     * Get the top proficiency badges for this GM.
     *
     * @return Collection<int, array{name: string, count: int}>
     */
    public function topProficiencies(int $limit = 3)
    {
        return app(ReviewAggregateService::class)
            ->topProficiencies($this, $limit);
    }

    /**
     * Get recent published reviews for this GM, paginated.
     *
     * @return LengthAwarePaginator<int, Review>
     */
    public function recentReviews(int $perPage = 5)
    {
        return app(ReviewAggregateService::class)
            ->recentReviews($this, $perPage);
    }
}
