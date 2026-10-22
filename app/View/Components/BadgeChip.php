<?php

namespace App\View\Components;

use App\Enums\BadgeRarity;
use App\Models\BadgeDefinition;
use App\Services\Badges\BadgeCopy;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The shared badge chip — the one render surface every badge display
 * flows through (M069 S04: profile shelf, GM Directory cards, gallery).
 *
 * Keeping icon/name/tier/state resolution in a single class is what stops
 * the three surfaces from drifting: hosts pass a BadgeDefinition plus the
 * lifecycle state they know (held/lapsed/dormant/unearned) and render
 * `<x-badge-chip>`; this class owns everything else:
 *
 * - icon: the S03 admin display-metadata override (badge_definitions.metadata)
 *   beats the synced registry column — "S04 display surfaces consume the
 *   metadata overrides curated here" — with a neutral trophy glyph as the
 *   last resort for un-glyphed factory rows.
 * - name / tier label: resolved through BadgeCopy — the one fallback
 *   chain the award notification and the gallery tier ladder also use
 *   (lang key -> Lang::has -> __() -> humanized registry key). Never a
 *   second convention, never a raw missing-key string.
 * - rarity: enum-cast from badge_definitions.rarity_class, null until the
 *   nightly badges:rarity job first populates it (D195). Drives colour
 *   mapping only — holder counts and %-gating belong to the gallery.
 */
class BadgeChip extends Component
{
    public readonly string $icon;

    public readonly string $name;

    public readonly ?string $tierLabel;

    public readonly ?BadgeRarity $rarity;

    public function __construct(
        public readonly BadgeDefinition $definition,
        public readonly ?string $tier = null,
        public readonly string $state = 'held',
        public readonly bool $featured = false,
        public readonly string $size = 'md',
    ) {
        $this->icon = $this->resolveIcon();
        $this->name = $this->resolveName();
        $this->tierLabel = $this->resolveTierLabel();
        $this->rarity = $this->resolveRarity();
    }

    public function render(): View
    {
        return view('components.badge-chip');
    }

    /**
     * Admin display-metadata icon override (S03) beats the synced registry
     * column; emoji_events is the neutral fallback for un-glyphed rows.
     */
    private function resolveIcon(): string
    {
        $metadata = $this->definition->metadata;
        $override = is_array($metadata) ? ($metadata['icon'] ?? null) : null;

        if (is_string($override) && $override !== '') {
            return $override;
        }

        return $this->definition->icon ?? 'emoji_events';
    }

    /**
     * Display copy resolves through BadgeCopy — the single fallback
     * chain shared with the award notification and the gallery ladder
     * (lang key -> __() -> humanized key; never a raw missing-key
     * string, never a second convention).
     */
    private function resolveName(): string
    {
        return BadgeCopy::name($this->definition->name_key, $this->definition->key);
    }

    private function resolveTierLabel(): ?string
    {
        return BadgeCopy::tierLabel($this->tier);
    }

    /**
     * Null-safe by construction: rarity_class is enum-cast on the model,
     * so it is either a BadgeRarity or null (before badges:rarity first
     * computes it). No tryFrom round-trip — the cast already validated.
     */
    private function resolveRarity(): ?BadgeRarity
    {
        return $this->definition->rarity_class;
    }
}
