<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignApplication extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['campaign_id', 'user_id', 'status', 'message'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
