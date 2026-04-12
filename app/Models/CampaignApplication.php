<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CampaignApplication extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['campaign_id', 'user_id', 'status', 'message'];

    protected static function booted(): void
    {
        static::creating(function (self $application) {
            if (empty($application->id)) {
                $application->id = (string) Str::uuid();
            }
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
