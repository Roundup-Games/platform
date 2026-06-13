<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Str;

class CampaignApplication extends Pivot
{
    protected $table = 'campaign_applications';

    protected $keyType = 'string';

    protected $fillable = ['campaign_id', 'user_id', 'status', 'message'];

    protected static function booted(): void
    {
        static::creating(function (self $application) {
            if (empty($application->id)) {
                $application->id = (string) Str::uuid();
            }
        });
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
