<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * @property string $id
 */
class GameSystemMechanic extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'slug', 'description'];

    protected static function booted(): void
    {
        static::creating(function (self $mechanic) {
            if (empty($mechanic->id)) {
                $mechanic->id = (string) Str::orderedUuid();
            }
            if (empty($mechanic->slug)) {
                $mechanic->slug = Str::slug($mechanic->name);
            }
        });
    }

    /**
     * @return BelongsToMany<GameSystem, $this>
     */
    public function gameSystems(): BelongsToMany
    {
        return $this->belongsToMany(GameSystem::class, 'game_system_mechanic');
    }

    /**
     * Self-referencing cross-link: mechanics similar to this one.
     *
     * @return BelongsToMany<GameSystemMechanic, $this>
     */
    public function similarMechanics(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'game_system_mechanic_relations',
            'mechanic_id',
            'related_mechanic_id'
        )->withPivot('type');
    }

    /**
     * Inverse: mechanics that reference this one as similar.
     *
     * @return BelongsToMany<GameSystemMechanic, $this>
     */
    public function inverseSimilarMechanics(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'game_system_mechanic_relations',
            'related_mechanic_id',
            'mechanic_id'
        )->withPivot('type');
    }

    /**
     * Return the translated name if a translation key exists, otherwise the DB name.
     * Keys follow the pattern: discovery.mech_{slug}
     */
    public function translatedName(): string
    {
        $key = "discovery.mech_{$this->slug}";
        $translated = __($key);

        return $translated === $key ? $this->name : $translated;
    }
}
