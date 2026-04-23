<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SessionZeroSurvey extends Model
{
    use HasFactory;

    protected $table = 'session_zero_surveys';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'gm_profile_id',
        'game_id',
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
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $survey) {
            if (empty($survey->id)) {
                $survey->id = (string) Str::uuid();
            }

            if (empty($survey->uuid)) {
                $survey->uuid = (string) Str::uuid();
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    public function gmProfile(): BelongsTo
    {
        return $this->belongsTo(GMProfile::class, 'gm_profile_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function confirmations(): HasMany
    {
        return $this->hasMany(SessionZeroConfirmation::class, 'session_zero_survey_id');
    }

    // ── Scopes ─────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeArchived($query)
    {
        return $query->where('status', 'archived');
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
