<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class GameSystemCategory extends Model
{
    protected $fillable = ['name', 'slug'];

    protected static function booted(): void
    {
        static::creating(function (self $category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    public function gameSystems(): BelongsToMany
    {
        return $this->belongsToMany(GameSystem::class, 'game_system_category');
    }

    /**
     * Return the translated name if a translation key exists, otherwise the DB name.
     * Keys follow the pattern: discovery.cat_{slug}
     */
    public function translatedName(): string
    {
        $key = "discovery.cat_{$this->slug}";
        $translated = __($key);

        return $translated === $key ? $this->name : $translated;
    }
}
