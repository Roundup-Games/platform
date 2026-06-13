<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * @property string $id
 */
class GameSystemFamily extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'slug'];

    protected static function booted(): void
    {
        static::creating(function (self $family) {
            if (empty($family->id)) {
                $family->id = (string) Str::orderedUuid();
            }
            if (empty($family->slug)) {
                $family->slug = Str::slug($family->name);
            }
        });
    }

    /** @return BelongsToMany<GameSystem, $this> */
    public function gameSystems(): BelongsToMany
    {
        return $this->belongsToMany(GameSystem::class, 'game_system_family');
    }
}
