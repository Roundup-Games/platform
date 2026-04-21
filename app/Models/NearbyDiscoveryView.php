<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NearbyDiscoveryView extends Model
{
    protected $fillable = [
        'user_id',
        'last_discovery_view',
        'geohash_4',
    ];

    protected function casts(): array
    {
        return [
            'last_discovery_view' => 'datetime',
        ];
    }

    /**
     * The user this discovery view tracking row belongs to.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
