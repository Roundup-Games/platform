<?php

use App\Livewire\Profile\AuthenticatedProfile;
use App\Livewire\Profile\PublicProfile;
use App\Models\BadgeDefinition;
use App\Models\BadgeUser;
use App\Models\User;

/**
 * Profile badge shelf (M069 S04 T3, spec §2.4): featured-first
 * composition on both profile surfaces and the toggleFeatured action
 * contract — ownership by construction, revoked never featureable, max-3
 * enforced with a loud validation error.
 *
 * Definitions use real registry lang keys so chip names resolve through
 * the lang files; assertions are wording-agnostic (K004) and compare
 * against __() lookups in the running locale, never literal copy.
 */

// ── Fixtures ──────────────────────────────────────────────────

/**
 * A definition pinned to a real badge name key, with controllable sort.
 */
function shelfDefinition(string $key, string $nameKey, int $sortOrder): BadgeDefinition
{
    return BadgeDefinition::factory()->create([
        'key' => $key,
        'name_key' => "badges.{$nameKey}",
        'sort_order' => $sortOrder,
    ]);
}

// ── Composition ───────────────────────────────────────────────

describe('Badge shelf composition', function () {
    it('renders featured holds first, then remaining holds in definition order, lapsed last', function () {
        $user = User::factory()->create();

        $debut = shelfDefinition('debut', 'content_badge_debut_name', 1);
        $firstSeat = shelfDefinition('first_seat', 'content_badge_first_seat_name', 2);
        $foundingTable = shelfDefinition('founding_table', 'content_badge_founding_table_name', 3);

        // Featured has the HIGHEST sort_order — it must still lead the shelf.
        BadgeUser::factory()->for($user)->for($foundingTable)->featured()->create();
        BadgeUser::factory()->for($user)->for($debut)->create();
        BadgeUser::factory()->for($user)->for($firstSeat)->lapsed()->create();

        $html = Livewire::actingAs($user)
            ->test(AuthenticatedProfile::class, ['user' => $user])
            ->assertOk()
            ->html();

        $featuredPos = strpos($html, __('badges.content_badge_founding_table_name'));
        $activePos = strpos($html, __('badges.content_badge_debut_name'));
        $lapsedPos = strpos($html, __('badges.content_badge_first_seat_name'));

        expect($featuredPos)->not->toBeFalse()
            ->and($activePos)->not->toBeFalse()
            ->and($lapsedPos)->not->toBeFalse()
            ->and($featuredPos)->toBeLessThan($activePos)
            ->and($activePos)->toBeLessThan($lapsedPos);
    });

    it('greys lapsed holds with the re-earn caption instead of hiding them', function () {
        $user = User::factory()->create();

        BadgeUser::factory()
            ->for($user)
            ->for(shelfDefinition('first_seat', 'content_badge_first_seat_name', 1))
            ->lapsed()
            ->create();

        Livewire::actingAs($user)
            ->test(AuthenticatedProfile::class, ['user' => $user])
            ->assertSee(__('badges.content_badge_first_seat_name'))
            ->assertSee(__('badges.content_badge_state_re_earn'));
    });

    it('hides revoked holds entirely from the shelf', function () {
        $user = User::factory()->create();

        BadgeUser::factory()
            ->for($user)
            ->for(shelfDefinition('debut', 'content_badge_debut_name', 1))
            ->create();
        BadgeUser::factory()
            ->for($user)
            ->for(shelfDefinition('chartered', 'content_badge_chartered_name', 2))
            ->revoked()
            ->create();

        Livewire::actingAs($user)
            ->test(AuthenticatedProfile::class, ['user' => $user])
            ->assertSee(__('badges.content_badge_debut_name'))
            ->assertDontSee(__('badges.content_badge_chartered_name'));
    });

    it('hides the whole shelf section when the profile has nothing displayable', function () {
        $user = User::factory()->create();

        // Revoked-only: rows exist, but nothing is displayable — no
        // empty-shelf clutter, not even the section title.
        BadgeUser::factory()
            ->for($user)
            ->for(shelfDefinition('debut', 'content_badge_debut_name', 1))
            ->revoked()
            ->create();

        Livewire::actingAs($user)
            ->test(AuthenticatedProfile::class, ['user' => $user])
            ->assertDontSee(__('badges.content_badge_shelf_title'))
            ->assertDontSee(__('badges.content_badge_debut_name'));
    });

    it('renders toggle buttons only for the profile owner', function () {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();

        $hold = BadgeUser::factory()
            ->for($owner)
            ->for(shelfDefinition('debut', 'content_badge_debut_name', 1))
            ->create();

        // Owner on the authenticated surface: toggles render. Directive
        // assertions go through raw html() containment — assertSee()
        // escapes the needle, and these carry quotes (PublicProfileTest
        // precedent).
        expect(Livewire::actingAs($owner)
            ->test(AuthenticatedProfile::class, ['user' => $owner])
            ->html())
            ->toContain('wire:click="toggleFeatured(\''.$hold->id.'\')"');

        // Owner on their own public surface: toggles render too.
        expect(Livewire::actingAs($owner)
            ->test(PublicProfile::class, ['user' => $owner])
            ->html())
            ->toContain('wire:click="toggleFeatured(\''.$hold->id.'\')"');

        // Another viewer: chips render, toggles do not.
        $viewerHtml = Livewire::actingAs($viewer)
            ->test(AuthenticatedProfile::class, ['user' => $owner])
            ->assertSee(__('badges.content_badge_debut_name'))
            ->html();

        expect($viewerHtml)->not->toContain('wire:click="toggleFeatured');

        // Guest: chips render, toggles do not.
        $guestHtml = Livewire::test(PublicProfile::class, ['user' => $owner])
            ->assertSee(__('badges.content_badge_debut_name'))
            ->html();

        expect($guestHtml)->not->toContain('wire:click="toggleFeatured');
    });
});

// ── toggleFeatured action ─────────────────────────────────────

describe('toggleFeatured action', function () {
    it('features and unfeatures an owned hold', function () {
        $user = User::factory()->create();

        $hold = BadgeUser::factory()
            ->for($user)
            ->for(shelfDefinition('debut', 'content_badge_debut_name', 1))
            ->create();

        Livewire::actingAs($user)
            ->test(AuthenticatedProfile::class, ['user' => $user])
            ->call('toggleFeatured', $hold->id);

        expect($hold->fresh()->is_featured)->toBeTrue();

        Livewire::actingAs($user)
            ->test(AuthenticatedProfile::class, ['user' => $user])
            ->call('toggleFeatured', $hold->id);

        expect($hold->fresh()->is_featured)->toBeFalse();
    });

    it('rejects the fourth featured hold with a validation error', function () {
        $user = User::factory()->create();

        $featured = [];
        for ($i = 0; $i < 3; $i++) {
            $featured[] = BadgeUser::factory()->for($user)
                ->for(shelfDefinition("featured_{$i}", 'content_badge_debut_name', $i))
                ->featured()
                ->create();
        }

        $fourth = BadgeUser::factory()->for($user)
            ->for(shelfDefinition('chartered', 'content_badge_chartered_name', 10))
            ->create();

        Livewire::actingAs($user)
            ->test(AuthenticatedProfile::class, ['user' => $user])
            ->call('toggleFeatured', $fourth->id)
            ->assertHasErrors('featuredLimit');

        expect($fourth->fresh()->is_featured)->toBeFalse()
            ->and(BadgeUser::where('user_id', $user->id)->where('is_featured', true)->count())->toBe(3);
    });

    it('still allows unfeaturing while three are featured (the swap path)', function () {
        $user = User::factory()->create();

        $hold = BadgeUser::factory()->for($user)
            ->for(shelfDefinition('debut', 'content_badge_debut_name', 1))
            ->featured()
            ->create();
        for ($i = 1; $i <= 2; $i++) {
            BadgeUser::factory()->for($user)
                ->for(shelfDefinition("featured_{$i}", 'content_badge_first_seat_name', $i))
                ->featured()
                ->create();
        }

        Livewire::actingAs($user)
            ->test(AuthenticatedProfile::class, ['user' => $user])
            ->call('toggleFeatured', $hold->id)
            ->assertHasNoErrors();

        expect($hold->fresh()->is_featured)->toBeFalse();
    });

    it('rejects toggling a hold owned by another user (client-tamper guard)', function () {
        $attacker = User::factory()->create();
        $victim = User::factory()->create();

        $victimHold = BadgeUser::factory()
            ->for($victim)
            ->for(shelfDefinition('debut', 'content_badge_debut_name', 1))
            ->create();

        Livewire::actingAs($attacker)
            ->test(AuthenticatedProfile::class, ['user' => $victim])
            ->call('toggleFeatured', $victimHold->id)
            ->assertHasNoErrors();

        expect($victimHold->fresh()->is_featured)->toBeFalse();
    });

    it('rejects featuring a revoked hold', function () {
        $user = User::factory()->create();

        $revoked = BadgeUser::factory()
            ->for($user)
            ->for(shelfDefinition('debut', 'content_badge_debut_name', 1))
            ->revoked()
            ->create();

        Livewire::actingAs($user)
            ->test(AuthenticatedProfile::class, ['user' => $user])
            ->call('toggleFeatured', $revoked->id)
            ->assertHasNoErrors();

        expect($revoked->fresh()->is_featured)->toBeFalse();
    });

    it('only counts non-revoked holds toward the featured maximum', function () {
        $user = User::factory()->create();

        // A crafted legacy state: a revoked row still flagged featured.
        BadgeUser::factory()->for($user)
            ->for(shelfDefinition('stale_revoked', 'content_badge_debut_name', 0))
            ->revoked()
            ->featured()
            ->create();
        for ($i = 1; $i <= 2; $i++) {
            BadgeUser::factory()->for($user)
                ->for(shelfDefinition("featured_{$i}", 'content_badge_first_seat_name', $i))
                ->featured()
                ->create();
        }

        $third = BadgeUser::factory()->for($user)
            ->for(shelfDefinition('chartered', 'content_badge_chartered_name', 10))
            ->create();

        // Two non-revoked featured → the third fits; the revoked one does
        // not consume a slot (and never renders).
        Livewire::actingAs($user)
            ->test(AuthenticatedProfile::class, ['user' => $user])
            ->call('toggleFeatured', $third->id)
            ->assertHasNoErrors();

        expect($third->fresh()->is_featured)->toBeTrue();
    });

    it('no-ops for guests on the public surface (crafted request guard)', function () {
        $owner = User::factory()->create();

        $hold = BadgeUser::factory()
            ->for($owner)
            ->for(shelfDefinition('debut', 'content_badge_debut_name', 1))
            ->create();

        Livewire::test(PublicProfile::class, ['user' => $owner])
            ->call('toggleFeatured', $hold->id);

        expect($hold->fresh()->is_featured)->toBeFalse();
    });
});

describe('lapsed holds and featuring', function () {
    it('never newly features a lapsed hold — the slot must go to a badge that displays', function () {
        $user = User::factory()->create();

        $hold = BadgeUser::factory()->for($user)
            ->for(shelfDefinition('debut', 'content_badge_debut_name', 1))
            ->lapsed()
            ->create();

        Livewire::actingAs($user)
            ->test(AuthenticatedProfile::class, ['user' => $user])
            ->call('toggleFeatured', $hold->id)
            ->assertHasNoErrors();

        expect($hold->fresh()->is_featured)->toBeFalse();
    });

    it('still allows unfeaturing an already-featured hold that later lapsed', function () {
        $user = User::factory()->create();

        $hold = BadgeUser::factory()->for($user)
            ->for(shelfDefinition('debut', 'content_badge_debut_name', 1))
            ->lapsed()
            ->featured()
            ->create();

        Livewire::actingAs($user)
            ->test(AuthenticatedProfile::class, ['user' => $user])
            ->call('toggleFeatured', $hold->id)
            ->assertHasNoErrors();

        // The belt-and-braces path: BadgeService::lapse already cleared
        // the flag; a stale row can still be un-featured, never stuck.
        expect($hold->fresh()->is_featured)->toBeFalse();
    });
});
