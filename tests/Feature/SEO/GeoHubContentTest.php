<?php

use App\Models\GameSystem;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;

// ── GEO hub-content contract ─────────────────────────
//
// The 2026-11-10 GEO audit found the platform's hub pages (discover,
// board-games, adventures, gms, game-systems, cities) carry no
// evergreen, self-contained explanatory copy — they render filters and
// empty states only, so AI engines (and pre-launch humans) find nothing
// cite-worthy. This suite pins the evergreen answer-first intros in
// both locales, the homepage heading-hygiene fix (the location gate no
// longer duplicates the section H2's exact heading as an H3), the
// BoardGameGeek source attribution on game-system descriptions (E-E-A-T:
// credit the source instead of implying authorship), and the
// content-review temporal note on pillar pages.
//
// K004: assertions compare __() output and config values — never
// literal copy. The BGG attribution presence is tied to bggUrl()
// (the model accessor), not to any URL literal.

describe('evergreen hub intros', function () {
    it('renders the evergreen intro on each hub page in both locales', function (string $path, string $locale, string $introKey) {
        get($path)->assertOk()->assertSee(__($introKey, [], $locale));
    })->with([
        'en discover' => ['/en/discover', 'en', 'discovery.content_discover_intro_1'],
        'de discover' => ['/de/discover', 'de', 'discovery.content_discover_intro_1'],
        'en board games' => ['/en/discover/board-games', 'en', 'discovery.content_board_games_intro_1'],
        'de board games' => ['/de/discover/board-games', 'de', 'discovery.content_board_games_intro_1'],
        'en adventures' => ['/en/discover/adventures', 'en', 'discovery.content_adventures_intro_1'],
        'de adventures' => ['/de/discover/adventures', 'de', 'discovery.content_adventures_intro_1'],
        'en gms' => ['/en/gms', 'en', 'gms.content_gm_directory_intro_1'],
        'de gms' => ['/de/gms', 'de', 'gms.content_gm_directory_intro_1'],
        'en game systems' => ['/en/game-systems', 'en', 'games.content_game_systems_intro_1'],
        'de game systems' => ['/de/game-systems', 'de', 'games.content_game_systems_intro_1'],
    ]);

    it('renders the secondary city-index paragraph in both locales', function (string $locale) {
        get("/{$locale}/cities")->assertOk()->assertSee(__('city-hubs.content_index_intro_secondary', [], $locale));
    })->with(['en', 'de']);
});

describe('homepage heading hygiene', function () {
    it('keeps the section H2 the only occurrence of its heading text — the location gate carries a distinct H3', function () {
        $html = get('/en')->assertOk()->content();

        // The section heading appears exactly once (the H2); the location
        // gate renders its own distinct heading instead of duplicating it.
        // e() matches Blade's {{ }} escaping (the key contains an apostrophe).
        expect(substr_count($html, e(__('discovery.content_what_s_happening_near_you', [], 'en'))))
            ->toBe(1)
            ->and($html)->toContain(e(__('discovery.content_location_gate_heading', [], 'en')));
    });
});

describe('BoardGameGeek source attribution', function () {
    it('credits and links the BGG source under a synced description', function () {
        $system = GameSystem::factory()->create([
            'slug' => 'attribution-system',
            'bgg_id' => 1234,
            'bgg_type' => 'boardgame',
            'description' => 'A test description synced from BoardGameGeek.',
        ]);

        get("/en/game-systems/{$system->slug}")
            ->assertOk()
            ->assertSee(__('games.content_description_source_bgg', [], 'en'))
            ->assertSee($system->bggUrl());
    });

    it('renders no attribution when the entry is not BGG-synced', function () {
        $system = GameSystem::factory()->create([
            'slug' => 'unlinked-system',
            'bgg_id' => null,
            'description' => 'A hand-maintained description with no BGG source.',
        ]);

        get("/en/game-systems/{$system->slug}")
            ->assertOk()
            ->assertDontSee(__('games.content_description_source_bgg', [], 'en'));
    });

    it('builds the canonical BGG URL from type and id', function () {
        $system = GameSystem::factory()->make([
            'bgg_id' => 42,
            'bgg_type' => 'boardgameexpansion',
        ]);

        expect($system->bggUrl())->toBe('https://boardgamegeek.com/boardgameexpansion/42')
            ->and(GameSystem::factory()->make(['bgg_id' => null, 'bgg_type' => 'boardgame'])->bggUrl())->toBeNull();
    });
});

describe('content-review temporal note', function () {
    it('renders the config-sourced last-reviewed note on pillar pages in both locales', function (string $locale) {
        $reviewedAt = config('content-reviews.pages.faq');
        expect($reviewedAt)->not->toBeNull('config/content-reviews.php must map the faq pillar');

        app()->setLocale($locale);
        $expected = __('pages.content_last_reviewed').': '
            .format_date(Carbon::parse($reviewedAt), 'month_year');

        get("/{$locale}/faq")->assertOk()->assertSee($expected);
    })->with(['en', 'de']);

    it('renders the same review date in the faq markdown mirror', function () {
        $reviewedAt = config('content-reviews.pages.faq');

        $body = get('/en/faq.md')->assertOk()->content();
        expect($body)->toContain(__('pages.content_last_reviewed', [], 'en'))
            ->and($body)->toContain(format_date(Carbon::parse($reviewedAt), 'month_year'));
    });
});
