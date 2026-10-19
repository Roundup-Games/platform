<?php

namespace App\Models;

use App\Models\Concerns\HasPlatformUuid;
use Carbon\Carbon;
use Database\Factories\SessionZeroSurveyVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable content snapshot of one published charter version (M070,
 * docs/spec/campaign-charter-concept.md §3).
 *
 * Rows are append-only history (like badge_events): publish and amendments
 * INSERT a new row and point session_zero_surveys.current_version at it —
 * no code path ever updates a version row. S02 signing pins confirmations
 * to these rows so signatures survive amendments.
 *
 * @property string $id
 * @property string $session_zero_survey_id
 * @property int $version_number
 * @property array<string, mixed> $content
 * @property int $content_schema
 * @property string|null $changelog
 * @property bool $is_material
 * @property Carbon $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SessionZeroSurveyVersion extends Model
{
    /** @use HasFactory<SessionZeroSurveyVersionFactory> */
    use HasFactory, HasPlatformUuid;

    protected $table = 'session_zero_survey_versions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'session_zero_survey_id',
        'version_number',
        'content',
        'content_schema',
        'changelog',
        'is_material',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'content_schema' => 'integer',
            'version_number' => 'integer',
            'is_material' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    // ── Relationships ──────────────────────────────────

    /**
     * @return BelongsTo<SessionZeroSurvey, $this>
     */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(SessionZeroSurvey::class, 'session_zero_survey_id');
    }

    /**
     * Signatures pinned to this immutable snapshot (M070/S02): participant
     * signatures and the GM countersignature, one row per (user, role) —
     * enforced by the partial unique index
     * session_zero_confirmations_version_user_role_unique.
     *
     * @return HasMany<SessionZeroConfirmation, $this>
     */
    public function confirmations(): HasMany
    {
        return $this->hasMany(SessionZeroConfirmation::class, 'session_zero_survey_version_id');
    }
}
