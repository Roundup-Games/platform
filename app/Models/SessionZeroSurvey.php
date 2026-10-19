<?php

namespace App\Models;

use App\Models\Concerns\HasPlatformUuid;
use Database\Factories\SessionZeroSurveyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property array<string, mixed> $content cast attribute (jsonb) — the
 *                                         schema-aware property extension types it string without this
 */
class SessionZeroSurvey extends Model
{
    /** @use HasFactory<SessionZeroSurveyFactory> */
    use HasFactory;

    use HasPlatformUuid;

    protected $table = 'session_zero_surveys';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'gm_profile_id',
        'game_id',
        'kind',
        'campaign_id',
        'current_version',
        'title',
        'content',
        'uuid',
        'status',
        'confirmation_count',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'confirmation_count' => 'integer',
            'current_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $survey) {
            if (empty($survey->uuid)) {
                $survey->uuid = (string) Str::uuid();
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    /**
     * @return BelongsTo<GMProfile, $this>
     */
    public function gmProfile(): BelongsTo
    {
        return $this->belongsTo(GMProfile::class, 'gm_profile_id');
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Owning campaign (charters only; null on legacy surveys).
     *
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Published version snapshots, newest first (immutable history:
     * publish/amend only ever INSERTs — concept §3).
     *
     * @return HasMany<SessionZeroSurveyVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(SessionZeroSurveyVersion::class, 'session_zero_survey_id')
            ->orderByDesc('version_number');
    }

    /**
     * @return HasMany<SessionZeroConfirmation, $this>
     */
    public function confirmations(): HasMany
    {
        return $this->hasMany(SessionZeroConfirmation::class, 'session_zero_survey_id');
    }

    // ── Scopes ─────────────────────────────────────────

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query)
    {
        return $query->where('status', 'active');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeArchived(Builder $query)
    {
        return $query->where('status', 'archived');
    }

    /**
     * Campaign charters only (excludes legacy surveys).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCharter(Builder $query)
    {
        return $query->where('kind', 'charter');
    }

    /**
     * Draft lifecycle state (charter authoring; legacy surveys are never
     * drafted — they are created active).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDraft(Builder $query)
    {
        return $query->where('status', 'draft');
    }

    // ── Helpers ────────────────────────────────────────

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCharter(): bool
    {
        return $this->kind === 'charter';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * The published snapshot current_version points at, or null while the
     * charter is still a draft (or the pointer dangles — defensive).
     */
    public function currentVersion(): ?SessionZeroSurveyVersion
    {
        if ($this->current_version === null) {
            return null;
        }

        return $this->versions()
            ->where('version_number', $this->current_version)
            ->first();
    }

    public function archive(): void
    {
        $this->update(['status' => 'archived']);
    }

    public function incrementConfirmationCount(): void
    {
        $this->increment('confirmation_count');
    }

    /**
     * Resolve a survey by its shareable UUID link.
     */
    public static function findByUuid(string $uuid): ?self
    {
        return static::where('uuid', $uuid)->first();
    }
}
