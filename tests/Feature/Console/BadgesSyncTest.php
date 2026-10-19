<?php

use App\Models\BadgeDefinition;
use App\Services\Badges\BadgeRegistry;

/**
 * badges:sync (M069 / D186): mirrors the code badge registry into the
 * badge_definitions runtime table. Idempotent by construction — the
 * content columns must converge to identical rows across runs while the
 * admin-owned columns (is_active) and unregistered rows stay untouched.
 *
 * Helper is top-level with a badgesSync- prefix: sibling Console tests
 * share the global namespace (LocationDriftSweepTest's convention).
 */

/**
 * @return array<string, array<string, mixed>> key => synced content columns
 */
function badgesSyncSnapshot(): array
{
    return BadgeDefinition::query()
        ->orderBy('key')
        ->get()
        ->mapWithKeys(function (BadgeDefinition $definition) {
            $tiers = $definition->tiers;

            // Postgres jsonb does not preserve key order (it sorts by
            // length, then bytewise — gold before bronze here). Tier rank
            // lives in the thresholds, never in key order, so content
            // comparisons normalize the order away.
            if (is_array($tiers)) {
                ksort($tiers);
            }

            return [
                $definition->key => [
                    'category' => $definition->category->value,
                    'mode' => $definition->mode->value,
                    'tiers' => $tiers,
                    'name_key' => $definition->name_key,
                    'description_key' => $definition->description_key,
                    'icon' => $definition->icon,
                    'sort_order' => $definition->sort_order,
                    'is_dormant' => $definition->is_dormant,
                    'eligibility' => $definition->eligibility->value,
                    'is_active' => $definition->is_active,
                ],
            ];
        })
        ->all();
}

it('syncs all registry definitions idempotently', function () {
    $this->artisan('badges:sync')->assertSuccessful();

    expect(BadgeDefinition::query()->count())->toBe(11);

    // Keys, categories, modes, tiers, copy keys, dormant flags and the
    // is_active default — the full registry mirror, pinned literally.
    $firstRun = badgesSyncSnapshot();

    expect($firstRun)->toBe([
        'campaign_compleat' => [
            'category' => 'gm_craft',
            'mode' => 'milestone',
            'tiers' => ['bronze' => 1, 'gold' => 5, 'silver' => 3],
            'name_key' => 'badges.content_badge_campaign_compleat_name',
            'description_key' => 'badges.content_badge_campaign_compleat_description',
            'icon' => 'auto_stories',
            'sort_order' => 50,
            'is_dormant' => true,
            'eligibility' => 'game_masters',
            'is_active' => true,
        ],
        'chartered' => [
            'category' => 'gm_craft',
            'mode' => 'milestone',
            'tiers' => ['bronze' => 1, 'gold' => 50, 'silver' => 10],
            'name_key' => 'badges.content_badge_chartered_name',
            'description_key' => 'badges.content_badge_chartered_description',
            'icon' => 'workspace_premium',
            'sort_order' => 40,
            'is_dormant' => false,
            'eligibility' => 'game_masters',
            'is_active' => true,
        ],
        'debut' => [
            'category' => 'milestones',
            'mode' => 'one_time',
            'tiers' => null,
            'name_key' => 'badges.content_badge_debut_name',
            'description_key' => 'badges.content_badge_debut_description',
            'icon' => 'rocket_launch',
            'sort_order' => 10,
            'is_dormant' => false,
            'eligibility' => 'all_users',
            'is_active' => true,
        ],
        'first_seat' => [
            'category' => 'milestones',
            'mode' => 'one_time',
            'tiers' => null,
            'name_key' => 'badges.content_badge_first_seat_name',
            'description_key' => 'badges.content_badge_first_seat_description',
            'icon' => 'event_seat',
            'sort_order' => 20,
            'is_dormant' => false,
            'eligibility' => 'all_users',
            'is_active' => true,
        ],
        'founding_table' => [
            'category' => 'support_tenure',
            'mode' => 'one_time',
            'tiers' => null,
            'name_key' => 'badges.content_badge_founding_table_name',
            'description_key' => 'badges.content_badge_founding_table_description',
            'icon' => 'foundation',
            'sort_order' => 30,
            'is_dormant' => false,
            'eligibility' => 'all_users',
            'is_active' => true,
        ],
        'guild_linked' => [
            'category' => 'community_infrastructure',
            'mode' => 'one_time',
            'tiers' => null,
            'name_key' => 'badges.content_badge_guild_linked_name',
            'description_key' => 'badges.content_badge_guild_linked_description',
            'icon' => 'link',
            'sort_order' => 90,
            'is_dormant' => false,
            'eligibility' => 'game_masters',
            'is_active' => true,
        ],
        'mentor' => [
            'category' => 'gm_craft',
            'mode' => 'milestone',
            'tiers' => ['bronze' => 5, 'gold' => 40, 'silver' => 15],
            'name_key' => 'badges.content_badge_mentor_name',
            'description_key' => 'badges.content_badge_mentor_description',
            'icon' => 'school',
            'sort_order' => 110,
            'is_dormant' => false,
            'eligibility' => 'game_masters',
            'is_active' => true,
        ],
        'regulars_table' => [
            'category' => 'participation',
            'mode' => 'recurring',
            'tiers' => ['bronze' => 3, 'gold' => 12, 'silver' => 6],
            'name_key' => 'badges.content_badge_regulars_table_name',
            'description_key' => 'badges.content_badge_regulars_table_description',
            'icon' => 'calendar_month',
            'sort_order' => 70,
            'is_dormant' => false,
            'eligibility' => 'all_users',
            'is_active' => true,
        ],
        'still_rolling' => [
            'category' => 'support_tenure',
            'mode' => 'milestone',
            'tiers' => ['year_1' => 1, 'year_3' => 3, 'year_5' => 5],
            'name_key' => 'badges.content_badge_still_rolling_name',
            'description_key' => 'badges.content_badge_still_rolling_description',
            'icon' => 'timelapse',
            'sort_order' => 80,
            'is_dormant' => false,
            'eligibility' => 'game_masters',
            'is_active' => true,
        ],
        'supporting_member' => [
            'category' => 'support_tenure',
            'mode' => 'recurring',
            'tiers' => ['year_1' => 0, 'year_2' => 12, 'year_3' => 24, 'year_5' => 60],
            'name_key' => 'badges.content_badge_supporting_member_name',
            'description_key' => 'badges.content_badge_supporting_member_description',
            'icon' => 'volunteer_activism',
            'sort_order' => 60,
            'is_dormant' => false,
            'eligibility' => 'all_users',
            'is_active' => true,
        ],
        'trusted_organizer' => [
            'category' => 'events_organizing',
            'mode' => 'requalifying',
            'tiers' => ['bronze' => 1, 'gold' => 6, 'silver' => 3],
            'name_key' => 'badges.content_badge_trusted_organizer_name',
            'description_key' => 'badges.content_badge_trusted_organizer_description',
            'icon' => 'verified',
            'sort_order' => 100,
            'is_dormant' => false,
            'eligibility' => 'game_masters',
            'is_active' => true,
        ],
    ]);

    // Second run converges to byte-identical content — no duplicate rows,
    // no churn in the synced columns.
    $this->artisan('badges:sync')->assertSuccessful();

    expect(BadgeDefinition::query()->count())->toBe(11)
        ->and(badgesSyncSnapshot())->toBe($firstRun);
});

it('never overwrites the admin is_active toggle on re-sync', function () {
    $this->artisan('badges:sync')->assertSuccessful();

    // Admin retires one dormant and one real definition between syncs.
    BadgeDefinition::query()->whereIn('key', ['debut', 'chartered'])->update(['is_active' => false]);

    $this->artisan('badges:sync')->assertSuccessful();

    expect(BadgeDefinition::query()->where('key', 'debut')->value('is_active'))->toBeFalse()
        ->and(BadgeDefinition::query()->where('key', 'chartered')->value('is_active'))->toBeFalse()
        // Definitions the admin never touched keep the default.
        ->and(BadgeDefinition::query()->where('key', 'first_seat')->value('is_active'))->toBeTrue();
});

it('leaves unregistered definition rows untouched', function () {
    // A definition removed from the registry in code: deactivation is
    // admin intent (S03 retirement flow) — sync must not delete it.
    BadgeDefinition::factory()->create([
        'key' => 'legacy_guild_badge',
        'is_active' => false,
    ]);

    $this->artisan('badges:sync')->assertSuccessful();

    expect(BadgeDefinition::query()->count())->toBe(12);

    $legacy = BadgeDefinition::query()->where('key', 'legacy_guild_badge')->first();

    expect($legacy)->not->toBeNull()
        ->and($legacy->is_active)->toBeFalse();
});

it('resolves every definition copy key in both locales with distinct EN/DE wording', function () {
    $registry = new BadgeRegistry;

    foreach ($registry->all() as $definition) {
        foreach ([$definition->nameKey, $definition->descriptionKey] as $copyKey) {
            $en = __($copyKey, [], 'en');
            $de = __($copyKey, [], 'de');

            // Wording-agnostic (K004): only that each key resolves in both
            // locales and DE carries genuinely distinct copy.
            expect($en)->not->toBe($copyKey);
            expect($en)->not->toBe('');
            expect($de)->not->toBe($copyKey);
            expect($de)->not->toBe('');
            expect($de)->not->toBe($en);
        }
    }
});
