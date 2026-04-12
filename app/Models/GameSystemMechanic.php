<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class GameSystemMechanic extends Model
{
    protected $fillable = ['name', 'slug'];

    protected static function booted(): void
    {
        static::creating(function (self $mechanic) {
            if (empty($mechanic->slug)) {
                $mechanic->slug = Str::slug($mechanic->name);
            }
        });
    }

    public function gameSystems(): BelongsToMany
    {
        return $this->belongsToMany(GameSystem::class, 'game_system_mechanic');
    }
}
