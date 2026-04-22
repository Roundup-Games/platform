<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class GameSystemCategory extends Model
{
    protected $fillable = ['name', 'slug', 'description'];

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
     * Self-referencing cross-link: categories similar to this one.
     */
    public function similarCategories(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'game_system_category_relations',
            'category_id',
            'related_category_id'
        )->withPivot('type');
    }

    /**
     * Inverse: categories that reference this one as similar.
     */
    public function inverseSimilarCategories(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'game_system_category_relations',
            'related_category_id',
            'category_id'
        )->withPivot('type');
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
