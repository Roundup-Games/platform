<?php

use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;
use App\Models\GameSystemPublisher;
use Illuminate\Support\Facades\Artisan;

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

    it('associates correct publisher (Darrington Press)', function () {
        $dh = GameSystem::where('source', 'startplaying')
            ->where('source_slug', 'daggerheart')
            ->firstOrFail();

        $publishers = $dh->publishers()->pluck('name')->toArray();

        expect($publishers)->toContain('Darrington Press');
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
