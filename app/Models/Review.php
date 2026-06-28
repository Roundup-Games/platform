<?php

namespace App\Models;

use App\Enums\GmProficiency;
use Database\Factories\ReviewFactory;
use Escalated\Laravel\Concerns\PresentsAsTicketSubject;
use Escalated\Laravel\Contracts\TicketSubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $content
 * @property int $rating
 * @property string|null $status
 * @property int|null $avg_rating
 * @property int|null $cnt
 * @property int|null $count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Review extends Model implements TicketSubject
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    use PresentsAsTicketSubject;

    /**
     * Reviews have no `name`/`title` attribute and no public detail page,
     * so the subject title identifies the review by its author and the URL
     * stays null (non-clickable chip in the ticket UI).
     */
    public function ticketSubjectTitle(): string
    {
        return __('Review by :name', ['name' => $this->reviewer->name ?? __('Unknown')]);
    }

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

    /** @return MorphTo<Model, $this> */
    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @return BelongsTo<GMProfile, $this>
     */
    public function gmProfile(): BelongsTo
    {
        return $this->belongsTo(GMProfile::class, 'gm_profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    // ── Scopes ─────────────────────────────────────────

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublished(Builder $query)
    {
        return $query->where('status', 'published');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeReported(Builder $query)
    {
        return $query->where('status', 'reported');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForGm(Builder $query, string $gmProfileId)
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
        /** @var string[] $tags */
        $tags = $this->proficiency_tags ?? [];

        return array_map(
            fn (string $value) => GmProficiency::from($value),
            $tags,
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
