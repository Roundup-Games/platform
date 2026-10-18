<?php

namespace App\Models;

use App\Enums\BadgeCategory;
use App\Enums\BadgeEligibility;
use App\Enums\BadgeMode;
use App\Enums\BadgeRarity;
use App\Models\Concerns\HasPlatformUuid;
use Carbon\Carbon;
use Database\Factories\BadgeDefinitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Runtime mirror of one entry in the code badge registry (D186, spec §2.1).
 *
 * Code is the source of truth for keys, tiers and grant logic; this row is
 * what awards join against and what admins toggle (is_active) or override
 * display metadata on. Synced idempotently by `badges:sync`, upserting on
 * `key`. Definitions are never hand-authored in the admin UI.
 *
 * @property string $id
 * @property string $key
 * @property BadgeCategory $category
 * @property BadgeMode $mode
 * @property array<string, mixed>|null $tiers
 * @property string $name_key
 * @property string $description_key
 * @property string|null $icon
 * @property int $sort_order
 * @property bool $is_active
 * @property bool $is_dormant
 * @property BadgeEligibility $eligibility
 * @property int $holders_count
 * @property int $eligible_count
 * @property BadgeRarity|null $rarity_class
 * @property Carbon|null $rarity_computed_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BadgeDefinition extends Model
{
    /** @use HasFactory<BadgeDefinitionFactory> */
    use HasFactory, HasPlatformUuid;

    protected $fillable = [
        'key',
        'category',
        'mode',
        'tiers',
        'name_key',
        'description_key',
        'icon',
        'sort_order',
        'is_active',
        'is_dormant',
        'eligibility',
        'holders_count',
        'eligible_count',
        'rarity_class',
        'rarity_computed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'category' => BadgeCategory::class,
            'mode' => BadgeMode::class,
            'tiers' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_dormant' => 'boolean',
            'eligibility' => BadgeEligibility::class,
            'holders_count' => 'integer',
            'eligible_count' => 'integer',
            'rarity_class' => BadgeRarity::class,
            'rarity_computed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    // ── Relationships ──────────────────────────────────

    /**
     * @return HasMany<BadgeUser, $this>
     */
    public function badgeUsers(): HasMany
    {
        return $this->hasMany(BadgeUser::class);
    }
}
