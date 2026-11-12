<?php

use App\Models\GameSystem;

use function Pest\Laravel\get;

// ═══════════════════════════════════════════════════════════
// GameSystemDetail — accessibility semantics (2026-11).
//
// Lighthouse flagged the metadata sidebar's <dl> (dt/dd wrapped in a
// second nested <div> — malforms the accessibility tree, fails
// definition-list/dlitem AND the agentic-browsing agent-accessibility-
// tree audit) and the site-header pills' aria-labels (accessible name
// must contain the visible text — label-content-name-mismatch).
// These tests pin both contracts. Tap-target sizing in the footer is
// styling-only and covered by Lighthouse, not asserted here.
// ═══════════════════════════════════════════════════════════

describe('GameSystemDetail - metadata definition list', function () {
    it('wraps every dt/dd group in exactly one div inside the dl', function () {
        $system = GameSystem::factory()->create([
            'slug' => 'a11y-sidebar-system',
            'min_players' => 2,
            'max_players' => 5,
            'year_released' => 2000,
        ]);

        $html = get("/en/game-systems/{$system->slug}")->assertOk()->getContent();

        $dl = preg_match('/<dl class="space-y-3[^"]*">(.*?)<\/dl>/s', $html, $m) === 1
            ? $m[1]
            : '';

        expect($dl)->not->toBe('')
            ->and(preg_match('/<dt/', $dl))->toBeGreaterThan(0)
            // The old shape nested an inner <div> directly around dt/dd —
            // the spec allows exactly one div level per group.
            ->and(preg_match('/<div[^>]*>\s*<dt/', $dl))->toBe(0)
            ->and(preg_match('/<div[^>]*>\s*<dd/', $dl))->toBe(0);
    });
});

describe('site-header accessible names', function () {
    it('composes the city pill label from its visible text plus action', function () {
        GameSystem::factory()->create(['slug' => 'a11y-header-system']);

        get('/en/game-systems/a11y-header-system')
            ->assertOk()
            ->assertSee(
                __('city-hubs.nav_cities').', '.__('common.aria_open_city_hubs'),
                false,
            );
    });

    it('composes the guest account chip label from its visible text plus action', function () {
        GameSystem::factory()->create(['slug' => 'a11y-account-system']);

        get('/en/game-systems/a11y-account-system')
            ->assertOk()
            ->assertSee(
                __('profile.content_account').', '.__('common.aria_open_account_menu'),
                false,
            );
    });
});
