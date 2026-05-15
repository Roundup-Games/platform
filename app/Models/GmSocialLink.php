<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmSocialLink extends Model
{
    use HasFactory;

    protected $table = 'gm_social_links';

    protected $fillable = [
        'user_id',
        'platform',
        'handle',
        'instance',
    ];

    protected function casts(): array
    {
        return [];
    }

    protected static function booted(): void
    {
        static::creating(function (self $link) {
            $link->url = app(\App\Services\GmSocialLinkService::class)
                ->generateUrl($link->platform, $link->handle, $link->instance);
        });

        static::updating(function (self $link) {
            if ($link->isDirty('platform') || $link->isDirty('handle') || $link->isDirty('instance')) {
                $link->url = app(\App\Services\GmSocialLinkService::class)
                    ->generateUrl($link->platform, $link->handle, $link->instance);
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
