<?php

namespace App\Models;

use App\Models\Concerns\HasPlatformUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * @property string $id
 */
class GameSystemDesigner extends Model
{
    use HasPlatformUuid;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'slug'];

    protected static function booted(): void
    {
        static::creating(function (self $designer) {
            if (empty($designer->slug)) {
                $designer->slug = Str::slug($designer->name);
            }
        });
    }

    /** @return BelongsToMany<GameSystem, $this> */
    public function gameSystems(): BelongsToMany
    {
        return $this->belongsToMany(GameSystem::class, 'game_system_designer');
    }
}
