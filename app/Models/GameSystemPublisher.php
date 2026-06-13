<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * @property string $id
 */
class GameSystemPublisher extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'slug'];

    protected static function booted(): void
    {
        static::creating(function (self $publisher) {
            if (empty($publisher->id)) {
                $publisher->id = (string) Str::orderedUuid();
            }
            if (empty($publisher->slug)) {
                $publisher->slug = Str::slug($publisher->name);
            }
        });
    }

    /** @return BelongsToMany<GameSystem, $this> */
    public function gameSystems(): BelongsToMany
    {
        return $this->belongsToMany(GameSystem::class, 'game_system_publisher');
    }
}
