<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $cnt
 * @property string|null $domain
 */
class ShortLinkHit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'short_link_id',
        'ip_address',
        'referer',
        'referer_domain',
        'user_agent',
        'country_code',
        'hit_at',
    ];

    protected function casts(): array
    {
        return [
            'hit_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────

    /** @return BelongsTo<ShortLink, $this> */
    public function shortLink(): BelongsTo
    {
        return $this->belongsTo(ShortLink::class);
    }
}
