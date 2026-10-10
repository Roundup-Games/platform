<?php

namespace App\Models;

use App\Models\Concerns\HasPlatformUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property-read User|null $user
 */
class NearbyDiscoveryView extends Model
{
    use HasPlatformUuid;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'last_discovery_view', 'geohash_4',
    ];

    protected function casts(): array
    {
        return [
            'last_discovery_view' => 'datetime',
        ];
    }

    /**
     * The user this discovery view tracking row belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
