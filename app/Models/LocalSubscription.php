<?php

namespace App\Models;

use App\Enums\LocalSubscriptionSource;
use App\Models\Concerns\HasPlatformUuid;
use Carbon\Carbon;
use Database\Factories\LocalSubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property LocalSubscriptionSource $source
 * @property string|null $granted_by
 * @property string|null $reason
 * @property Carbon|null $ends_at
 */
class LocalSubscription extends Model
{
    /** @use HasFactory<LocalSubscriptionFactory> */
    use HasFactory;

    use HasPlatformUuid;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'membership_type_id', 'status',
        'starts_at', 'ends_at', 'canceled_at',
        'source', 'granted_by', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'canceled_at' => 'datetime',
            'source' => LocalSubscriptionSource::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<MembershipType, $this> */
    public function membershipType(): BelongsTo
    {
        return $this->belongsTo(MembershipType::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * True when this subscription was granted as an ops comp
     * (source=comp) rather than self-serve signup.
     */
    public function isComp(): bool
    {
        return $this->source === LocalSubscriptionSource::Comp;
    }

    /**
     * Same truth as scopeActive(): status active AND the period has
     * not ended (ends_at null = open-ended, the self-serve/GM default).
     */
    public function isActive(): bool
    {
        return $this->status === 'active'
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public function isCanceled(): bool
    {
        return $this->status === 'canceled';
    }

    /**
     * Benefits flow only while status is active AND the period has not
     * ended (ends_at null = open-ended). One WHERE group so the ends_at
     * OR cannot leak into outer query contexts.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query)
    {
        return $query->where(function (Builder $query) {
            $query->where('status', 'active')
                ->where(function (Builder $query) {
                    $query->whereNull('ends_at')
                        ->orWhere('ends_at', '>', now());
                });
        });
    }

    /**
     * Check if this is a GM plan subscription.
     */
    public function isGmPlan(): bool
    {
        return ($this->membershipType->metadata['gm_plan'] ?? false) === true;
    }
}
