<?php

namespace Tests\Feature\Services;

use App\Models\City;
use App\Models\CityHubSetting;
use App\Services\CityHubSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// CityHubSettings (62-04 T01): the DB-over-config threshold layer beneath
// the city hub qualification guard. Precedence contract: a present
// city_hub_settings row wins over config('cityhubs.*'); absence falls back
// to the config defaults (3 / 2). The config fallback is what keeps the
// runtime config([...]) overrides in CityDirectoryServiceTest working —
// those tests never create settings rows, so they always exercise the
// config layer and the 49-test baseline stays green.
//
// Both thresholds resolve in ONE Cache::rememberForever('city-hubs:settings')
// lookup; set() upserts both rows and forgets that entry.
//
// This file is also the first test to exercise the new cities and
// city_hub_settings tables (schema migrations run once at suite bootstrap,
// per-project convention — DatabaseTransactions isolates each test).

beforeEach(function () {
    Cache::flush();
});

// ═══════════════════════════════════════════════════════════
// CONFIG FALLBACK
// ═══════════════════════════════════════════════════════════

describe('config fallback', function () {
    it('falls back to the cityhubs config defaults when no rows exist', function () {
        $settings = app(CityHubSettings::class);

        expect($settings->minUpcomingSessions())->toBe(3)
            ->and($settings->minVerifiedVenues())->toBe(2);
    });

    it('honours runtime config overrides without settings rows', function () {
        config(['cityhubs.min_upcoming_sessions' => 5, 'cityhubs.min_verified_venues' => 4]);

        $settings = app(CityHubSettings::class);

        expect($settings->minUpcomingSessions())->toBe(5)
            ->and($settings->minVerifiedVenues())->toBe(4);
    });

    it('coerces env-style string config values and degrades non-numeric ones to the default', function () {
        config(['cityhubs.min_upcoming_sessions' => '6', 'cityhubs.min_verified_venues' => 'oops']);

        $settings = app(CityHubSettings::class);

        expect($settings->minUpcomingSessions())->toBeInt()->toBe(6)
            // Non-numeric config degrades to the hard default, never 0.
            ->and($settings->minVerifiedVenues())->toBe(2);
    });

    it('resolves both thresholds in one query and caches across instances', function () {
        $settings = app(CityHubSettings::class);

        DB::enableQueryLog();
        $settings->minUpcomingSessions();
        $settings->minVerifiedVenues();
        // Even a fresh service instance must hit the shared cache entry,
        // not re-run the resolution query.
        app(CityHubSettings::class)->minUpcomingSessions();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($queries)->toHaveCount(1)
            ->and(Cache::has(CityHubSettings::CACHE_KEY))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// DB OVERRIDE
// ═══════════════════════════════════════════════════════════

describe('db override', function () {
    it('a present row wins over the config value per key', function () {
        config(['cityhubs.min_upcoming_sessions' => 5, 'cityhubs.min_verified_venues' => 4]);

        CityHubSetting::query()->create([
            'key' => CityHubSettings::KEY_MIN_UPCOMING_SESSIONS,
            'value' => 7,
        ]);

        $settings = app(CityHubSettings::class);

        // The populated key takes its DB row...
        expect($settings->minUpcomingSessions())->toBe(7)
            // ...while the unpopulated key still falls back to config —
            // degradation is per-key, not all-or-nothing.
            ->and($settings->minVerifiedVenues())->toBe(4);
    });
});

// ═══════════════════════════════════════════════════════════
// set()
// ═══════════════════════════════════════════════════════════

describe('set', function () {
    it('upserts both rows and clears the shared cache entry', function () {
        $settings = app(CityHubSettings::class);

        // Warm the forever-cache with the config fallback resolution.
        expect($settings->minUpcomingSessions())->toBe(3);
        expect(Cache::has(CityHubSettings::CACHE_KEY))->toBeTrue();

        $settings->set(9, 6);

        // set() must have forgotten the entry — otherwise rememberForever
        // would keep serving the pre-set resolution.
        expect($settings->minUpcomingSessions())->toBe(9)
            ->and($settings->minVerifiedVenues())->toBe(6);
    });

    it('updates in place on repeated writes instead of duplicating rows', function () {
        $settings = app(CityHubSettings::class);
        $settings->set(4, 2);
        $settings->set(8, 5);

        expect(CityHubSetting::count())->toBe(2)
            ->and(CityHubSetting::query()->pluck('value', 'key')->all())->toBe([
                CityHubSettings::KEY_MIN_UPCOMING_SESSIONS => 8,
                CityHubSettings::KEY_MIN_VERIFIED_VENUES => 5,
            ])
            ->and($settings->minUpcomingSessions())->toBe(8)
            ->and($settings->minVerifiedVenues())->toBe(5);
    });
});

// ═══════════════════════════════════════════════════════════
// CITY MODEL (data layer round-trip)
// ═══════════════════════════════════════════════════════════

describe('city model', function () {
    it('round-trips a translatable intro with a generated uuid pk and curation casts', function () {
        $city = City::factory()->create([
            'slug' => 'berlin',
            'city' => 'Berlin',
            'region_prefix' => 'u33',
            'upcoming_activity_count' => 12,
            'verified_venues_count' => 3,
        ]);

        expect($city->id)->toBeString()->not->toBe('')
            ->and($city->region_prefix)->toBe('u33')
            ->and($city->featured)->toBeFalse()
            ->and($city->hidden)->toBeFalse()
            ->and($city->upcoming_activity_count)->toBeInt()->toBe(12)
            ->and($city->verified_venues_count)->toBeInt()->toBe(3);

        $city->setTranslation('intro', 'en', 'Discover sessions in Berlin.');
        $city->setTranslation('intro', 'de', 'Entdecke Runden in Berlin.');
        $city->save();

        $fresh = City::query()->findOrFail($city->id);

        expect($fresh->getTranslation('intro', 'en'))->toBe('Discover sessions in Berlin.')
            ->and($fresh->getTranslation('intro', 'de'))->toBe('Entdecke Runden in Berlin.');
    });

    it('factory states cover the curation surface', function () {
        $city = City::factory()->withIntro()->featured()->create();

        expect($city->featured)->toBeTrue()
            ->and($city->hidden)->toBeFalse()
            ->and($city->getTranslation('intro', 'en'))->toBeString()
            ->and($city->getTranslation('intro', 'de'))->toBeString();

        $hidden = City::factory()->hidden()->create();

        expect($hidden->hidden)->toBeTrue()
            ->and($hidden->featured)->toBeFalse();
    });
});
