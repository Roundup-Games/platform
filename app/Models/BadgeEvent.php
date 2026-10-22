<?php

namespace App\Models;

use App\Enums\BadgeEventAction;
use App\Enums\BadgeSource;
use App\Models\Concerns\HasPlatformUuid;
use Carbon\Carbon;
use Database\Factories\BadgeEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only audit-trail entry for one badge lifecycle action (D186,
 * spec §2.3): awarded / upgraded / revoked / lapsed / requalified —
 * with actor, reason, source and the evidence that justified the
 * action (manual grants are Awarded with source=manual).
 *
 * Rows are immutable facts: the table has no updated_at, the model runs
 * with $timestamps = false, and writers set created_at explicitly
 * (cf. ActivityLog).
 *
 * @property string $id
 * @property string $badge_user_id
 * @property BadgeEventAction $action
 * @property string|null $actor_id
 * @property string|null $reason
 * @property BadgeSource $source
 * @property string|null $evidence_type
 * @property string|null $evidence_id
 * @property Carbon|null $created_at
 */
class BadgeEvent extends Model
{
    /** @use HasFactory<BadgeEventFactory> */
    use HasFactory, HasPlatformUuid;

    public $timestamps = false;

    protected $fillable = [
        'badge_user_id',
        'action',
        'actor_id',
        'reason',
        'source',
        'evidence_type',
        'evidence_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => BadgeEventAction::class,
            'source' => BadgeSource::class,
            'created_at' => 'datetime',
        ];
    }

    // ── Relationships ──────────────────────────────────

    /**
     * @return BelongsTo<BadgeUser, $this>
     */
    public function badgeUser(): BelongsTo
    {
        return $this->belongsTo(BadgeUser::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function evidence(): MorphTo
    {
        return $this->morphTo();
    }
}
