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

    /**
     * The tiers map in registry order: ascending threshold (key => value;
     * e.g. bronze 1 → silver 3 → gold 5, or year_1 0 → year_2 12 …).
     *
     * READ ORDER MATTERS: tiers is a jsonb column, and Postgres does not
     * preserve object key order — it canonicalizes (length first, then
     * bytewise), so reading ->tiers yields gold before bronze/silver
     * for most ladders. The registry's threshold order is the display
     * truth (progression), so every surface that iterates tiers — the
     * gallery ladder, the admin tier select, tier labels — MUST use
     * this accessor, never array_keys($definition->tiers).
     *
     * @return list<string> tier keys, lowest threshold first
     */
    public function orderedTierKeys(): array
    {
        $tiers = $this->tiers;

        if (! is_array($tiers) || $tiers === []) {
            return [];
        }

        $keys = array_keys($tiers);

        usort($keys, function (string $a, string $b) use ($tiers): int {
            $aThreshold = $tiers[$a] ?? 0;
            $bThreshold = $tiers[$b] ?? 0;

            return (is_int($aThreshold) ? $aThreshold : 0) <=> (is_int($bThreshold) ? $bThreshold : 0);
        });

        return $keys;
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
