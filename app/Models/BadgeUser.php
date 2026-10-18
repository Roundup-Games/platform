<?php

namespace App\Models;

use App\Enums\BadgeSource;
use App\Models\Concerns\HasPlatformUuid;
use Carbon\Carbon;
use Database\Factories\BadgeUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One held badge per user (D186, spec §2.2).
 *
 * The unique(user_id, badge_definition_id) constraint is the idempotency
 * backstop: a second award of the same badge violates the constraint
 * instead of duplicating a shelf entry. Tier upgrades UPDATE this row;
 * revocation and lapse stamp timestamps rather than deleting, so the
 * audit trail (BadgeEvent) keeps its referent and "last held" display
 * stays legible (spec principle 4: lapse honestly, never vanish).
 *
 * @property string $id
 * @property string $user_id
 * @property string $badge_definition_id
 * @property string|null $tier
 * @property BadgeSource $source
 * @property Carbon $first_awarded_at
 * @property Carbon|null $last_awarded_at
 * @property Carbon|null $requalified_through
 * @property Carbon|null $lapsed_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property bool $is_featured
 * @property string|null $evidence_type
 * @property string|null $evidence_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read BadgeDefinition $badgeDefinition
 */
class BadgeUser extends Model
{
    /** @use HasFactory<BadgeUserFactory> */
    use HasFactory, HasPlatformUuid;

    // Table is deliberately singular (badge_user, cf. the migration): one
    // row per (user, badge) — a join table with a lifecycle, not a
    // collection of "badge users".
    protected $table = 'badge_user';

    protected $fillable = [
        'user_id',
        'badge_definition_id',
        'tier',
        'source',
        'first_awarded_at',
        'last_awarded_at',
        'requalified_through',
        'lapsed_at',
        'revoked_at',
        'revoked_reason',
        'evidence_type',
        'evidence_id',
        'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'source' => BadgeSource::class,
            'first_awarded_at' => 'datetime',
            'last_awarded_at' => 'datetime',
            'requalified_through' => 'datetime',
            'lapsed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'is_featured' => 'boolean',
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

    /**
     * @return BelongsTo<BadgeDefinition, $this>
     */
    public function badgeDefinition(): BelongsTo
    {
        return $this->belongsTo(BadgeDefinition::class);
    }

    /** @return MorphTo<Model, $this> */
    public function evidence(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<BadgeEvent, $this>
     */
    public function badgeEvents(): HasMany
    {
        return $this->hasMany(BadgeEvent::class);
    }
}
