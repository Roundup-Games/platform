<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShortLinkHit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'short_link_id',
        'ip_address',
        'referer',
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

    public function shortLink(): BelongsTo
    {
        return $this->belongsTo(ShortLink::class);
    }
}
