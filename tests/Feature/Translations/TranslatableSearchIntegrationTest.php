<?php

use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use App\Traits\QueriesTranslatableColumns;
use Illuminate\Database\Eloquent\Builder;

/**
 * Integration tests for locale-scoped JSON queries on translatable fields.
 *
 * Complements the unit-level TranslatableSearchTest by exercising multi-locale
 * scenarios, locale isolation, cross-model queries, and fallback behavior.
 */
beforeEach(function () {
    $this->user = User::factory()->create();

    // Helper to invoke the trait's protected method from test context
    $this->searchLocale = function (string $modelClass, string $field, string $term, string $locale) {
        $originalLocale = app()->getLocale();
        app()->setLocale($locale);

        $results = $modelClass::where(function ($q) use ($field, $term) {
            $trait = new class
            {
                use QueriesTranslatableColumns;

                public function run(Builder $q, string $field, string $term): void
                {
                    $this->whereTranslatableLike($q, $field, $term);
                }
            };
            $trait->run($q, $field, $term);
        })->get();

        app()->setLocale($originalLocale);

        return $results;
    };
});

// ── 1. Finds events by English name in en locale ─────────────────────────

it('finds events by English name in en locale', function () {
    Event::factory()->create([
        'name' => ['en' => 'Grand Tournament of Dragons'],
        'organizer_id' => $this->user->id,
    ]);
    Event::factory()->create([
        'name' => ['en' => 'Weekly Board Game Meetup'],
        'organizer_id' => $this->user->id,
    ]);

    $results = ($this->searchLocale)(Event::class, 'name', 'Tournament', 'en');

    expect($results)->toHaveCount(1);
    expect($results->first()->getTranslation('name', 'en'))->toBe('Grand Tournament of Dragons');
});

// ── 2. Finds events by German name in de locale ──────────────────────────

it('finds events by German name in de locale', function () {
    Event::factory()->create([
        'name' => ['en' => 'Grand Tournament', 'de' => 'Großes Turnier der Drachen'],
        'organizer_id' => $this->user->id,
    ]);
    Event::factory()->create([
        'name' => ['en' => 'Weekly Meetup', 'de' => 'Wöchentliches Brettspiel-Treffen'],
        'organizer_id' => $this->user->id,
    ]);

    $results = ($this->searchLocale)(Event::class, 'name', 'Turnier', 'de');

    expect($results)->toHaveCount(1);
    expect($results->first()->getTranslation('name', 'de'))->toBe('Großes Turnier der Drachen');
});

// ── 3. Does not cross-match locales ──────────────────────────────────────

it('does not find German-only text when searching in en locale', function () {
    Event::factory()->create([
        'name' => ['en' => 'English Event Name', 'de' => 'Deutscher Veranstaltungsname'],
        'organizer_id' => $this->user->id,
    ]);

    // Search for a substring that only exists in the German translation
    $results = ($this->searchLocale)(Event::class, 'name', 'Veranstaltungsname', 'en');

    expect($results)->toHaveCount(0);
});

it('does not find English-only text when searching in de locale', function () {
    Event::factory()->create([
        'name' => ['en' => 'English Event Name', 'de' => 'Deutscher Veranstaltungsname'],
        'organizer_id' => $this->user->id,
    ]);

    // Search for a substring that only exists in the English translation
    $results = ($this->searchLocale)(Event::class, 'name', 'English Event', 'de');

    expect($results)->toHaveCount(0);
});

it('does not cross-match across completely different locales', function () {
    Event::factory()->create([
        'name' => ['en' => 'Dragon SlayerCon'],
        'organizer_id' => $this->user->id,
    ]);
    Event::factory()->create([
        'name' => ['de' => 'Drachentöter Convention'],
        'organizer_id' => $this->user->id,
    ]);

    // English search should only find the en-only event
    $enResults = ($this->searchLocale)(Event::class, 'name', 'Dragon', 'en');
    expect($enResults)->toHaveCount(1);
    expect($enResults->first()->getTranslation('name', 'en'))->toBe('Dragon SlayerCon');

    // German search should only find the de-only event
    $deResults = ($this->searchLocale)(Event::class, 'name', 'Drache', 'de');
    expect($deResults)->toHaveCount(1);
    expect($deResults->first()->getTranslation('name', 'de'))->toBe('Drachentöter Convention');
});

// ── 4. Finds games and campaigns by localized name ────────────────────────

it('finds games by localized name in both locales', function () {
    $gameSystem = GameSystem::factory()->create(['name' => ['en' => 'Test System']]);

    Game::factory()->create([
        'name' => ['en' => 'Epic Dungeon Crawl', 'de' => 'Epischer Dungeon-Crawl'],
        'owner_id' => $this->user->id,
        'game_system_id' => $gameSystem->id,
    ]);
    Game::factory()->create([
        'name' => ['en' => 'Peaceful Farming Sim', 'de' => 'Friedliche Farmsimulation'],
        'owner_id' => $this->user->id,
        'game_system_id' => $gameSystem->id,
    ]);

    $enResults = ($this->searchLocale)(Game::class, 'name', 'Dungeon', 'en');
    expect($enResults)->toHaveCount(1);
    expect($enResults->first()->getTranslation('name', 'en'))->toBe('Epic Dungeon Crawl');

    $deResults = ($this->searchLocale)(Game::class, 'name', 'Farmsimulation', 'de');
    expect($deResults)->toHaveCount(1);
    expect($deResults->first()->getTranslation('name', 'de'))->toBe('Friedliche Farmsimulation');
});

it('finds campaigns by localized name', function () {
    $gameSystem = GameSystem::factory()->create(['name' => ['en' => 'Test System']]);

    Campaign::factory()->create([
        'name' => ['en' => 'Rise of the Dragon Lords', 'de' => 'Aufstieg der Drachenlords'],
        'owner_id' => $this->user->id,
        'game_system_id' => $gameSystem->id,
    ]);
    Campaign::factory()->create([
        'name' => ['en' => 'Lost Mine of Phandelver', 'de' => 'Verlorene Mine von Phandelver'],
        'owner_id' => $this->user->id,
        'game_system_id' => $gameSystem->id,
    ]);

    $enResults = ($this->searchLocale)(Campaign::class, 'name', 'Dragon', 'en');
    expect($enResults)->toHaveCount(1);
    expect($enResults->first()->getTranslation('name', 'en'))->toBe('Rise of the Dragon Lords');

    $deResults = ($this->searchLocale)(Campaign::class, 'name', 'Drachenlords', 'de');
    expect($deResults)->toHaveCount(1);
    expect($deResults->first()->getTranslation('name', 'de'))->toBe('Aufstieg der Drachenlords');
});

// ── 5. GameSystem search works in both locales ───────────────────────────

it('finds GameSystem by name in both locales', function () {
    GameSystem::factory()->create([
        'name' => ['en' => 'Dungeons and Dragons 5e', 'de' => 'Dungeons und Drachen 5e'],
    ]);
    GameSystem::factory()->create([
        'name' => ['en' => 'Pathfinder Second Edition', 'de' => 'Pathfinder Zweite Edition'],
    ]);

    $enResults = ($this->searchLocale)(GameSystem::class, 'name', 'Dragons', 'en');
    expect($enResults)->toHaveCount(1);
    expect($enResults->first()->getTranslation('name', 'en'))->toBe('Dungeons and Dragons 5e');

    $deResults = ($this->searchLocale)(GameSystem::class, 'name', 'Drachen', 'de');
    expect($deResults)->toHaveCount(1);
    expect($deResults->first()->getTranslation('name', 'de'))->toBe('Dungeons und Drachen 5e');
});

// ── 6. Fallback behavior documentation ───────────────────────────────────

it('does not find en-only event when searching in de locale without fallback query', function () {
    // Event only has English translation — no 'de' key in JSON
    Event::factory()->create([
        'name' => ['en' => 'English Only Event'],
        'organizer_id' => $this->user->id,
    ]);

    // The SQL WHERE name->>'de' ILIKE '%English%' will not match because
    // the 'de' key does not exist in the JSON object.
    $results = ($this->searchLocale)(Event::class, 'name', 'English', 'de');

    expect($results)->toHaveCount(0);
});

it('finds en-only event via fallback by querying both locales', function () {
    // Event only has English translation
    Event::factory()->create([
        'name' => ['en' => 'English Only Event'],
        'organizer_id' => $this->user->id,
    ]);
    Event::factory()->create([
        'name' => ['en' => 'Other Event', 'de' => 'Anderes Event'],
        'organizer_id' => $this->user->id,
    ]);

    // Simulate a fallback search: check de first, fall back to en
    $originalLocale = app()->getLocale();

    app()->setLocale('de');
    $results = Event::where(function ($q) {
        $trait = new class
        {
            use QueriesTranslatableColumns;

            public function runWhere(Builder $q, string $field, string $term): void
            {
                $this->whereTranslatableLike($q, $field, $term);
            }

            public function runOrWhere(Builder $q, string $field, string $term): void
            {
                $this->orWhereTranslatableLike($q, $field, $term);
            }
        };
        // Search in de locale
        $trait->runWhere($q, 'name', 'English Only');
        // Fall back to en locale via orWhere with explicit locale switch
    })->get();
    app()->setLocale($originalLocale);

    // de search alone finds nothing (no 'de' key in first event)
    expect($results)->toHaveCount(0);

    // Now do a proper fallback: search de first, then en
    app()->setLocale('de');
    $fallbackResults = Event::where(function ($q) {
        $trait = new class
        {
            use QueriesTranslatableColumns;

            public function runWhere(Builder $q, string $field, string $term): void
            {
                $this->whereTranslatableLike($q, $field, $term);
            }
        };
        $trait->runWhere($q, 'name', 'English Only');
    })->orWhere(function ($q) {
        app()->setLocale('en');
        $trait = new class
        {
            use QueriesTranslatableColumns;

            public function runWhere(Builder $q, string $field, string $term): void
            {
                $this->whereTranslatableLike($q, $field, $term);
            }
        };
        $trait->runWhere($q, 'name', 'English Only');
    })->get();
    app()->setLocale($originalLocale);

    // Fallback to en finds the English-only event
    expect($fallbackResults)->toHaveCount(1);
    expect($fallbackResults->first()->getTranslation('name', 'en'))->toBe('English Only Event');
});
