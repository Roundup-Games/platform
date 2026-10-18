<?php

namespace App\Livewire\Badges;

use App\Enums\BadgeCategory;
use App\Models\BadgeDefinition;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use RalphJSmit\Laravel\SEO\Support\SEOData;

/**
 * Guest-visible badge gallery (M069 S04 T5, spec §2.4): the public
 * discovery + SEO surface for every active badge definition.
 *
 * One unpaginated query over the registry-bounded badge_definitions table
 * (definitions are code-registered, never user-authored, so cardinality is
 * small by construction). Cards render through the shared x-badge-chip
 * plus the rarity columns the nightly badges:rarity job owns (D195):
 *
 * - holders count ALWAYS (boundary-map honesty rule),
 * - "held by X% of eligible" only while eligible_count >=
 *   BadgeRarity::ELIGIBLE_THRESHOLD — no rare-% claims on a tiny
 *   population,
 * - dormant definitions stay visible as greyed "coming soon" cards
 *   (aspirational loop), inactive ones are hidden entirely.
 */
#[Layout('components.public-layout')]
class BadgesGallery extends Component
{
    /** Selected category filter ('' = all categories), mirrored in the URL. */
    #[Url]
    public string $category = '';

    // ── Actions ─────────────────────────────────────────

    /**
     * Pill toggle (GmDirectory::toggleSpecialization pattern): clicking the
     * active category pill clears back to All; clicking All always clears.
     */
    public function toggleCategory(string $value): void
    {
        $this->category = $this->category === $value ? '' : $value;
    }

    // ── Render ──────────────────────────────────────────

    public function render(): View
    {
        // $category is client-controlled twice over (#[Url] query-string
        // hydration and Livewire wire payloads) — clamp against the enum
        // before anything reads it, so a tampered value degrades to "All"
        // instead of filtering to nothing (MAX_DISPLAY_COUNT mindset).
        $this->category = $this->effectiveCategory();

        seo(new SEOData(
            title: __('badges.title_badge_gallery'),
            description: __('badges.seo_description_badge_gallery'),
        ));

        $definitions = BadgeDefinition::query()
            ->where('is_active', true)
            ->when($this->category !== '', fn ($query) => $query->where('category', $this->category))
            ->orderBy('sort_order')
            ->get();

        return view('livewire.badges.badges-gallery', [
            'definitions' => $definitions,
            'categories' => BadgeCategory::cases(),
        ]);
    }

    /**
     * The category if it names a real badge category, '' otherwise.
     */
    private function effectiveCategory(): string
    {
        return in_array($this->category, BadgeCategory::values(), true) ? $this->category : '';
    }
}
