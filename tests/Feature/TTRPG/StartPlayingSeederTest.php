<?php

use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;
use App\Models\GameSystemPublisher;
use Illuminate\Support\Facades\Artisan;

// ═══════════════════════════════════════════════════════════
// SEEDER SMOKE: RUN ONCE, VERIFY COUNTS
// ═══════════════════════════════════════════════════════════

describe('StartPlayingSeeder — counts', function () {
    beforeEach(function () {
        Artisan::call('db:seed', ['--class' => 'StartPlayingSeeder']);
    });

    it('seeds 71 TTRPG systems', function () {
        expect(GameSystem::where('type', 'ttrpg')->where('source', 'startplaying')->count())->toBe(71);
    });

    it('seeds 40 genres', function () {
        // SP crawl data contains exactly 40 genres; additional genres may be
        // created as fallbacks from system data, so we assert >= 40.
        $crawlSlugs = array_column(require database_path('seeders/data/ttrpg-genres.php'), 'slug');
        $present = GameSystemCategory::whereIn('slug', $crawlSlugs)->count();
        expect($present)->toBe(40);
    });

    it('seeds 17 mechanics', function () {
        $crawlSlugs = array_column(require database_path('seeders/data/ttrpg-mechanics.php'), 'slug');
        $present = GameSystemMechanic::whereIn('slug', $crawlSlugs)->count();
        expect($present)->toBe(17);
    });

    it('seeds publishers extracted from system data', function () {
        // 32 unique publishers from SP data
        expect(GameSystemPublisher::count())->toBeGreaterThanOrEqual(32);
    });
});

// ═══════════════════════════════════════════════════════════
// SPOT-CHECK: DAGGERHEART
// ═══════════════════════════════════════════════════════════

describe('StartPlayingSeeder — Daggerheart spot-check', function () {
    beforeEach(function () {
        Artisan::call('db:seed', ['--class' => 'StartPlayingSeeder']);
    });

    it('creates Daggerheart with correct type and source', function () {
        $dh = GameSystem::where('source', 'startplaying')
            ->where('source_slug', 'daggerheart')
            ->firstOrFail();

        expect($dh->type)->toBe('ttrpg')
            ->and($dh->source)->toBe('startplaying')
            ->and($dh->name)->toBe('Daggerheart');
    });

    it('parses player range into min/max/optimal', function () {
        $dh = GameSystem::where('source', 'startplaying')
            ->where('source_slug', 'daggerheart')
            ->firstOrFail();

        expect($dh->player_range)->toBe('3-6 Players')
            ->and($dh->min_players)->toBe(3)
            ->and($dh->max_players)->toBe(6)
            ->and($dh->optimal_players)->toBe(5);
    });

    it('parses release year from complex date string', function () {
        $dh = GameSystem::where('source', 'startplaying')
            ->where('source_slug', 'daggerheart')
            ->firstOrFail();

        // "May 20th, 2025" → 2025
        expect($dh->year_released)->toBe(2025);
    });

    it('associates correct genres (Fantasy, Imaginative)', function () {
        $dh = GameSystem::where('source', 'startplaying')
            ->where('source_slug', 'daggerheart')
            ->firstOrFail();

        $genreSlugs = $dh->categories()->pluck('slug')->toArray();

        expect($genreSlugs)->toContain('fantasy')
            ->and($genreSlugs)->toContain('imaginative')
            ->and(count($genreSlugs))->toBe(2);
    });

    it('associates correct mechanic', function () {
        $dh = GameSystem::where('source', 'startplaying')
            ->where('source_slug', 'daggerheart')
            ->firstOrFail();

        $mechSlugs = $dh->mechanics()->pluck('slug')->toArray();

        // Mechanic "d12 System" → slug "d12-system"
        expect(count($mechSlugs))->toBeGreaterThanOrEqual(1);
        expect($mechSlugs)->toContain('d12-system');
    });

    it('associates correct publisher (Darrington Press)', function () {
        $dh = GameSystem::where('source', 'startplaying')
            ->where('source_slug', 'daggerheart')
            ->firstOrFail();

        $publishers = $dh->publishers()->pluck('name')->toArray();

        expect($publishers)->toContain('Darrington Press');
    });

    it('stores faq_content as non-empty JSON array', function () {
        $dh = GameSystem::where('source', 'startplaying')
            ->where('source_slug', 'daggerheart')
            ->firstOrFail();

        expect($dh->faq_content)->not->toBeNull()
            ->and(is_array($dh->faq_content))->toBeTrue()
            ->and(count($dh->faq_content))->toBeGreaterThanOrEqual(5);

        // Spot-check first FAQ has question/answer keys
        expect($dh->faq_content[0])->toHaveKeys(['question', 'answer']);
    });

    it('stores external_links as non-empty array', function () {
        $dh = GameSystem::where('source', 'startplaying')
            ->where('source_slug', 'daggerheart')
            ->firstOrFail();

        expect($dh->external_links)->not->toBeNull()
            ->and(is_array($dh->external_links))->toBeTrue()
            ->and(count($dh->external_links))->toBeGreaterThanOrEqual(3);
    });

    it('stores showcases as non-empty array', function () {
        $dh = GameSystem::where('source', 'startplaying')
            ->where('source_slug', 'daggerheart')
            ->firstOrFail();

        expect($dh->showcases)->not->toBeNull()
            ->and(is_array($dh->showcases))->toBeTrue()
            ->and(count($dh->showcases))->toBeGreaterThanOrEqual(1);
    });

    it('stores sp_rating and sp_review_count', function () {
        $dh = GameSystem::where('source', 'startplaying')
            ->where('source_slug', 'daggerheart')
            ->firstOrFail();

        expect($dh->sp_rating)->not->toBeNull()
            ->and($dh->sp_rating)->toBeGreaterThan(4.0)
            ->and($dh->sp_review_count)->not->toBeNull()
            ->and($dh->sp_review_count)->toBeGreaterThan(0);
    });
});

// ═══════════════════════════════════════════════════════════
// SPOT-CHECK: GENRE CROSS-LINKS
// ═══════════════════════════════════════════════════════════

describe('StartPlayingSeeder — genre cross-links', function () {
    beforeEach(function () {
        Artisan::call('db:seed', ['--class' => 'StartPlayingSeeder']);
    });

    it('Fantasy genre has similar genres linked', function () {
        $fantasy = GameSystemCategory::where('slug', 'fantasy')->firstOrFail();

        $similarSlugs = $fantasy->similarCategories()->pluck('slug')->toArray();

        // From crawl data: cozy, dark-fantasy, gritty-fantasy, high-fantasy, low-magic, urban-fantasy
        expect(count($similarSlugs))->toBeGreaterThanOrEqual(5)
            ->and($similarSlugs)->toContain('dark-fantasy')
            ->and($similarSlugs)->toContain('high-fantasy')
            ->and($similarSlugs)->toContain('urban-fantasy');
    });

    it('similar categories have pivot type = similar', function () {
        $fantasy = GameSystemCategory::where('slug', 'fantasy')->firstOrFail();

        $all = $fantasy->similarCategories()->get();

        foreach ($all as $related) {
            expect($related->pivot->type)->toBe('similar');
        }
    });
});

// ═══════════════════════════════════════════════════════════
// SPOT-CHECK: MECHANIC CROSS-LINKS
// ═══════════════════════════════════════════════════════════

describe('StartPlayingSeeder — mechanic cross-links', function () {
    beforeEach(function () {
        Artisan::call('db:seed', ['--class' => 'StartPlayingSeeder']);
    });

    it('d20 System mechanic has similar mechanics linked', function () {
        $d20 = GameSystemMechanic::where('slug', 'd20-system')->firstOrFail();

        $similarSlugs = $d20->similarMechanics()->pluck('slug')->toArray();

        // From crawl data: powered-by-mörk-borg, osr, essence-20-system
        expect(count($similarSlugs))->toBeGreaterThanOrEqual(3)
            ->and($similarSlugs)->toContain('osr')
            ->and($similarSlugs)->toContain('essence-20-system');
    });

    it('similar mechanics have pivot type = similar', function () {
        $d20 = GameSystemMechanic::where('slug', 'd20-system')->firstOrFail();

        $all = $d20->similarMechanics()->get();

        foreach ($all as $related) {
            expect($related->pivot->type)->toBe('similar');
        }
    });
});

// ═══════════════════════════════════════════════════════════
// PUBLISHER DEDUPLICATION
// ═══════════════════════════════════════════════════════════

describe('StartPlayingSeeder — publisher deduplication', function () {
    it('creates exactly one record per unique publisher slug', function () {
        Artisan::call('db:seed', ['--class' => 'StartPlayingSeeder']);

        // Load all publisher names from crawl data
        $systemsData = require database_path('seeders/data/ttrpg-systems.php');
        $publisherNames = [];
        foreach ($systemsData as $system) {
            if (!empty($system['publisher'])) {
                $publisherNames[$system['publisher']] = true;
            }
        }

        // Each unique name should produce exactly one publisher record
        foreach (array_keys($publisherNames) as $name) {
            $slug = \Illuminate\Support\Str::slug($name);
            $count = GameSystemPublisher::where('slug', $slug)->count();
            expect($count)->toBe(1, "Publisher '{$name}' (slug: {$slug}) should exist exactly once, found {$count}");
        }
    });
});

// ═══════════════════════════════════════════════════════════
// IDEMPOTENCY: RUN TWICE
// ═══════════════════════════════════════════════════════════

describe('StartPlayingSeeder — idempotency', function () {
    it('produces identical counts when run twice', function () {
        // First run
        Artisan::call('db:seed', ['--class' => 'StartPlayingSeeder']);

        $systemCount1 = GameSystem::where('type', 'ttrpg')->where('source', 'startplaying')->count();
        $categoryCount1 = GameSystemCategory::count();
        $mechanicCount1 = GameSystemMechanic::count();
        $publisherCount1 = GameSystemPublisher::count();

        // Second run
        Artisan::call('db:seed', ['--class' => 'StartPlayingSeeder']);

        $systemCount2 = GameSystem::where('type', 'ttrpg')->where('source', 'startplaying')->count();
        $categoryCount2 = GameSystemCategory::count();
        $mechanicCount2 = GameSystemMechanic::count();
        $publisherCount2 = GameSystemPublisher::count();

        expect($systemCount2)->toBe($systemCount1, 'System count should not increase on re-run')
            ->and($categoryCount2)->toBe($categoryCount1, 'Category count should not increase on re-run')
            ->and($mechanicCount2)->toBe($mechanicCount1, 'Mechanic count should not increase on re-run')
            ->and($publisherCount2)->toBe($publisherCount1, 'Publisher count should not increase on re-run');
    });

    it('preserves cross-links on re-run without duplication', function () {
        Artisan::call('db:seed', ['--class' => 'StartPlayingSeeder']);

        $fantasyLinks1 = GameSystemCategory::where('slug', 'fantasy')
            ->firstOrFail()
            ->similarCategories()
            ->count();

        Artisan::call('db:seed', ['--class' => 'StartPlayingSeeder']);

        $fantasyLinks2 = GameSystemCategory::where('slug', 'fantasy')
            ->firstOrFail()
            ->similarCategories()
            ->count();

        expect($fantasyLinks2)->toBe($fantasyLinks1, 'Fantasy cross-links should not duplicate on re-run');

        // Also check mechanic cross-links
        $d20Links1 = GameSystemMechanic::where('slug', 'd20-system')
            ->firstOrFail()
            ->similarMechanics()
            ->count();

        Artisan::call('db:seed', ['--class' => 'StartPlayingSeeder']);

        $d20Links2 = GameSystemMechanic::where('slug', 'd20-system')
            ->firstOrFail()
            ->similarMechanics()
            ->count();

        expect($d20Links2)->toBe($d20Links1, 'd20 mechanic cross-links should not duplicate on re-run');
    });
});
