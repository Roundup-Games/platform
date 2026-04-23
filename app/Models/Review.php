<?php

namespace App\Models;

use App\Enums\GmProficiency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class Review extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'reviewable_type',
        'reviewable_id',
        'reviewer_id',
        'gm_profile_id',
        'rating',
        'body',
        'proficiency_tags',
        'status',
        'reported_at',
        'reported_by',
        'report_reason',
        'reply',
        'replied_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'proficiency_tags' => 'array',
            'reported_at' => 'datetime',
            'replied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $review) {
            if (empty($review->id)) {
                $review->id = (string) Str::uuid();
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function gmProfile(): BelongsTo
    {
        return $this->belongsTo(GMProfile::class, 'gm_profile_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    // ── Scopes ─────────────────────────────────────────

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeReported($query)
    {
        return $query->where('status', 'reported');
    }

    public function scopeForGm($query, string $gmProfileId)
    {
        return $query->where('gm_profile_id', $gmProfileId);
    }

    // ── Helpers ────────────────────────────────────────

    /**
     * Get proficiency tags as enum instances.
     *
     * @return GmProficiency[]
     */
    public function getProficiencyEnums(): array
    {
        return array_map(
            fn (string $value) => GmProficiency::from($value),
            $this->proficiency_tags ?? [],
        );
    }

    public function isReported(): bool
    {
        return $this->status === 'reported';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Valid reasons for reporting a review.
     */
    public const REPORT_REASONS = [
        'inappropriate',
        'spam',
        'harassment',
        'other',
    ];

    /**
     * Report this review for moderation.
     *
     * Sets status to 'reported', records reporter, reason, and timestamp.
     * Does NOT hide the review — only admin action does that.
     */
    public function report(string $reporterId, string $reason): void
    {
        $this->update([
            'status' => 'reported',
            'reported_by' => $reporterId,
            'report_reason' => $reason,
            'reported_at' => now(),
        ]);
    }
}
