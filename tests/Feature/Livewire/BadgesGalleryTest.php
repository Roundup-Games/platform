<?php

use App\Enums\BadgeCategory;
use App\Enums\BadgeEligibility;
use App\Enums\BadgeRarity;
use App\Livewire\Badges\BadgesGallery;
use App\Models\BadgeDefinition;
use Illuminate\Support\Number;
use Livewire\Livewire;

/**
 * Guest-visible badge gallery (M069 S04 T5, spec §2.4): every active
 * definition with category pills, rarity display and the boundary-map
 * honesty rule — holder count ALWAYS, "% of eligible" only at or above
 * BadgeRarity::ELIGIBLE_THRESHOLD. Definitions use real registry lang
 * keys so names/descriptions resolve through the lang files; assertions
 * are wording-agnostic via __() lookups (K004).
 */

// ── Fixtures ──────────────────────────────────────────────────

/**
 * A definition pinned to a real badge name key with controllable sort,
 * category and rarity columns. Defaults mirror the debut registry entry.
 */
function galleryDefinition(array $overrides = []): BadgeDefinition
{
    return BadgeDefinition::factory()->create(array_merge([
        'key' => 'debut',
        'category' => BadgeCategory::Milestones,
        'name_key' => 'badges.content_badge_debut_name',
        'description_key' => 'badges.content_badge_debut_description',
        'sort_order' => 10,
    ], $overrides));
}

// ── Composition ───────────────────────────────────────────────

describe('Badge gallery composition', function () {
    it('renders every active definition in registry order for a guest', function () {
        galleryDefinition(['sort_order' => 10]);
        galleryDefinition([
            'key' => 'chartered',
            'category' => BadgeCategory::GmCraft,
            'name_key' => 'badges.content_badge_chartered_name',
            'description_key' => 'badges.content_badge_chartered_description',
            'sort_order' => 40,
        ]);
        galleryDefinition([
            'key' => 'regulars_table',
            'category' => BadgeCategory::Participation,
            'name_key' => 'badges.content_badge_regulars_table_name',
            'description_key' => 'badges.content_badge_regulars_table_description',
            'sort_order' => 70,
        ]);

        Livewire::test(BadgesGallery::class)
            ->assertOk()
            ->assertSeeInOrder([
                __('badges.content_badge_debut_name'),
                __('badges.content_badge_chartered_name'),
                __('badges.content_badge_regulars_table_name'),
            ]);
    });

    it('renders the gallery page over HTTP for guests in both locales', function () {
        galleryDefinition();

        $this->get('/en/badges')->assertOk()
            ->assertSee(__('badges.title_badge_gallery', [], 'en'))
            ->assertSee(__('badges.content_badge_debut_name', [], 'en'));

        $this->get('/de/badges')->assertOk()
            ->assertSee(__('badges.title_badge_gallery', [], 'de'))
            ->assertSee(__('badges.content_badge_debut_name', [], 'de'));
    });

    it('hides inactive definitions entirely', function () {
        BadgeDefinition::factory()->inactive()->create([
            'key' => 'retired_badge',
            'name_key' => 'badges.content_badge_first_seat_name',
        ]);
        galleryDefinition(['key' => 'active_badge']);

        Livewire::test(BadgesGallery::class)
            ->assertOk()
            ->assertSee(__('badges.content_badge_debut_name'))
            ->assertDontSee(__('badges.content_badge_first_seat_name'));
    });

    it('renders dormant definitions greyed with the coming-soon caption', function () {
        galleryDefinition([
            'key' => 'campaign_compleat',
            'category' => BadgeCategory::GmCraft,
            'name_key' => 'badges.content_badge_campaign_compleat_name',
            'description_key' => 'badges.content_badge_campaign_compleat_description',
            'is_dormant' => true,
        ]);

        Livewire::test(BadgesGallery::class)
            ->assertOk()
            ->assertSee(__('badges.content_badge_campaign_compleat_name'))
            ->assertSee(__('badges.content_badge_state_coming_soon'))
            ->assertSee('opacity-60');
    });

    it('renders the tier ladder for tiered badges in threshold order', function () {
        galleryDefinition([
            'key' => 'regulars_table',
            'tiers' => ['bronze' => 3, 'silver' => 6, 'gold' => 12],
        ]);

        // ORDER is the regression pin: jsonb does not preserve key
        // order (it canonicalizes — gold sorts before bronze/silver for
        // this ladder), so the ladder must come through
        // BadgeDefinition::orderedTierKeys(), threshold-ascending.
        Livewire::test(BadgesGallery::class)
            ->assertOk()
            ->assertSeeInOrder([
                __('badges.content_badge_tier_bronze'),
                __('badges.content_badge_tier_silver'),
                __('badges.content_badge_tier_gold'),
            ]);
    });
});

// ── Category pills ────────────────────────────────────────────

describe('Badge gallery category filter', function () {
    it('filters the grid by category pill and toggles back to all', function () {
        galleryDefinition(['sort_order' => 10]);
        galleryDefinition([
            'key' => 'chartered',
            'category' => BadgeCategory::GmCraft,
            'name_key' => 'badges.content_badge_chartered_name',
            'description_key' => 'badges.content_badge_chartered_description',
            'sort_order' => 40,
        ]);

        Livewire::test(BadgesGallery::class)
            ->assertOk()
            ->call('toggleCategory', 'gm_craft')
            ->assertSet('category', 'gm_craft')
            ->assertSee(__('badges.content_badge_chartered_name'))
            ->assertDontSee(__('badges.content_badge_debut_name'))
            ->call('toggleCategory', 'gm_craft')
            ->assertSet('category', '')
            ->assertSee(__('badges.content_badge_debut_name'))
            ->assertSee(__('badges.content_badge_chartered_name'));
    });

    it('renders the empty state for a category with no badges', function () {
        galleryDefinition();

        Livewire::test(BadgesGallery::class)
            ->assertOk()
            ->call('toggleCategory', 'events_organizing')
            ->assertDontSee(__('badges.content_badge_debut_name'))
            ->assertSee(__('badges.content_badge_gallery_empty'));
    });

    it('sanitizes a tampered category URL value to all', function () {
        galleryDefinition();
        galleryDefinition([
            'key' => 'chartered',
            'category' => BadgeCategory::GmCraft,
            'name_key' => 'badges.content_badge_chartered_name',
        ]);

        // #[Url] hydration path: an unknown category degrades to All.
        $this->get('/en/badges?category=bogus')->assertOk()
            ->assertSee(__('badges.content_badge_debut_name', [], 'en'))
            ->assertSee(__('badges.content_badge_chartered_name', [], 'en'));

        // Wire payload path: setting an unknown category normalizes on render.
        Livewire::test(BadgesGallery::class)
            ->set('category', 'bogus')
            ->assertOk()
            ->assertSet('category', '')
            ->assertSee(__('badges.content_badge_debut_name'))
            ->assertSee(__('badges.content_badge_chartered_name'));
    });
});

// ── Rarity display (boundary-map honesty rule, D195) ──────────

describe('Badge gallery rarity display', function () {
    it('always renders the holders count', function () {
        galleryDefinition([
            'holders_count' => 7,
            'eligible_count' => 3,
        ]);

        Livewire::test(BadgesGallery::class)
            ->assertOk()
            ->assertSee(trans_choice('badges.content_badge_holders', 7))
            ->assertDontSee(__('badges.content_badge_share_of_eligible', ['percent' => round(7 / 3 * 100, 1)]));
    });

    it('shows the eligible share at the threshold and hides it below', function () {
        // Exactly at ELIGIBLE_THRESHOLD (100): the share MAY show.
        galleryDefinition([
            'key' => 'at_threshold',
            'holders_count' => 10,
            'eligible_count' => BadgeRarity::ELIGIBLE_THRESHOLD,
        ]);

        // Just below (99): the holder count shows, the share must not.
        galleryDefinition([
            'key' => 'below_threshold',
            'name_key' => 'badges.content_badge_first_seat_name',
            'description_key' => 'badges.content_badge_first_seat_description',
            'holders_count' => 10,
            'eligible_count' => BadgeRarity::ELIGIBLE_THRESHOLD - 1,
            'sort_order' => 20,
        ]);

        Livewire::test(BadgesGallery::class)
            ->assertOk()
            ->assertSee(__('badges.content_badge_share_of_eligible', ['percent' => 10]))
            ->assertDontSee(__('badges.content_badge_share_of_eligible', ['percent' => round(10 / 99 * 100, 1)]));
    });

    it('labels the share with the Game Master denominator for GM badges', function () {
        galleryDefinition([
            'key' => 'chartered',
            'category' => BadgeCategory::GmCraft,
            'name_key' => 'badges.content_badge_chartered_name',
            'eligibility' => BadgeEligibility::GameMasters,
            'holders_count' => 25,
            'eligible_count' => 200,
        ]);

        Livewire::test(BadgesGallery::class)
            ->assertOk()
            ->assertSee(__('badges.content_badge_share_of_eligible_gms', ['percent' => round(25 / 200 * 100, 1)]))
            ->assertDontSee(__('badges.content_badge_share_of_eligible', ['percent' => round(25 / 200 * 100, 1)]));
    });

    it('renders the rarity class label when the nightly job computed one', function () {
        galleryDefinition(['rarity_class' => BadgeRarity::Rare]);

        Livewire::test(BadgesGallery::class)
            ->assertOk()
            ->assertSee(__('badges.content_badge_rarity_rare'));
    });

    it('renders without a rarity class before the first nightly run', function () {
        galleryDefinition(['rarity_class' => null]);

        Livewire::test(BadgesGallery::class)
            ->assertOk()
            ->assertSee(__('badges.content_badge_debut_name'));
    });
});

// ── Locale (D180) ─────────────────────────────────────────────

describe('Badge gallery localization', function () {
    it('renders the gallery title, category label and badge names in German', function () {
        app()->setLocale('de');

        galleryDefinition();
        galleryDefinition([
            'key' => 'chartered',
            'category' => BadgeCategory::GmCraft,
            'name_key' => 'badges.content_badge_chartered_name',
            'description_key' => 'badges.content_badge_chartered_description',
            'sort_order' => 40,
        ]);

        // The eligible-share percent localizes through Number::format
        // (comma decimal in German), never a raw float string.
        galleryDefinition([
            'key' => 'share_de',
            'name_key' => 'badges.content_badge_first_seat_name',
            'description_key' => 'badges.content_badge_first_seat_description',
            'holders_count' => 25,
            'eligible_count' => 200,
            'sort_order' => 50,
        ]);

        Livewire::test(BadgesGallery::class)
            ->assertOk()
            ->assertSee(__('badges.title_badge_gallery', [], 'de'))
            ->assertSee(__('badges.category_gm_craft', [], 'de'))
            ->assertSee(__('badges.content_badge_debut_name', [], 'de'))
            ->assertSee(__('badges.content_badge_share_of_eligible', [
                'percent' => Number::format(12.5, maxPrecision: 1),
            ], 'de'))
            ->assertDontSee(__('badges.title_badge_gallery', [], 'en'));
    });
});
