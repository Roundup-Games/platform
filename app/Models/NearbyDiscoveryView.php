<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class NearbyDiscoveryView extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'last_discovery_view', 'geohash_4',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) Str::orderedUuid();
            }
        });
    }

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
