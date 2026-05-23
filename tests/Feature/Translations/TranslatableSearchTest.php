<?php

use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use App\Traits\QueriesTranslatableColumns;
use Illuminate\Database\Eloquent\Builder;

/**
 * Test the QueriesTranslatableColumns trait directly and verify
 * it works with spatie-translatable JSON columns.
 */
beforeEach(function () {
    // Create a user for ownership
    $this->user = User::factory()->create();
});

it('finds Event by translatable name using JSON path query', function () {
    Event::factory()->create([
        'name' => ['en' => 'Grand Tournament of Dragons'],
        'organizer_id' => $this->user->id,
    ]);
    Event::factory()->create([
        'name' => ['en' => 'Casual Board Game Night'],
        'organizer_id' => $this->user->id,
    ]);

    $search = 'Dragon';
    $results = Event::where(function ($q) use ($search) {
        $trait = new class {
            use QueriesTranslatableColumns;
            public function testWhere(Builder $q, string $field, string $search): void
            {
                $this->whereTranslatableLike($q, $field, $search);
            }
        };
        $trait->testWhere($q, 'name', $search);
    })->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->getTranslation('name', 'en'))->toBe('Grand Tournament of Dragons');
});

it('finds Game by translatable name using JSON path query', function () {
    $gameSystem = GameSystem::factory()->create([
        'name' => ['en' => 'Test System'],
    ]);

    Game::factory()->create([
        'name' => ['en' => 'Epic Dungeon Crawl'],
        'owner_id' => $this->user->id,
        'game_system_id' => $gameSystem->id,
    ]);
    Game::factory()->create([
        'name' => ['en' => 'Peaceful Farming Sim'],
        'owner_id' => $this->user->id,
        'game_system_id' => $gameSystem->id,
    ]);

    $search = 'Dungeon';
    $results = Game::where(function ($q) use ($search) {
        $trait = new class {
            use QueriesTranslatableColumns;
            public function testWhere(Builder $q, string $field, string $search): void
            {
                $this->whereTranslatableLike($q, $field, $search);
            }
        };
        $trait->testWhere($q, 'name', $search);
    })->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->getTranslation('name', 'en'))->toBe('Epic Dungeon Crawl');
});

it('search is case-insensitive via ILIKE', function () {
    Event::factory()->create([
        'name' => ['en' => 'UPPERCASE Event Name'],
        'organizer_id' => $this->user->id,
    ]);

    $search = 'uppercase';
    $results = Event::where(function ($q) use ($search) {
        $trait = new class {
            use QueriesTranslatableColumns;
            public function testWhere(Builder $q, string $field, string $search): void
            {
                $this->whereTranslatableLike($q, $field, $search);
            }
        };
        $trait->testWhere($q, 'name', $search);
    })->get();

    expect($results)->toHaveCount(1);
});

it('escapes SQL wildcards in search term', function () {
    Event::factory()->create([
        'name' => ['en' => '100% Real Event'],
        'organizer_id' => $this->user->id,
    ]);
    Event::factory()->create([
        'name' => ['en' => 'Some other event'],
        'organizer_id' => $this->user->id,
    ]);

    // Searching for literal '100%' should only match the first event
    $search = '100%';
    $results = Event::where(function ($q) use ($search) {
        $trait = new class {
            use QueriesTranslatableColumns;
            public function testWhere(Builder $q, string $field, string $search): void
            {
                $this->whereTranslatableLike($q, $field, $search);
            }
        };
        $trait->testWhere($q, 'name', $search);
    })->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->getTranslation('name', 'en'))->toBe('100% Real Event');
});

it('finds GameSystem by translatable name', function () {
    GameSystem::factory()->create([
        'name' => ['en' => 'Dungeons and Dragons 5e'],
    ]);
    GameSystem::factory()->create([
        'name' => ['en' => 'Pathfinder Second Edition'],
    ]);

    $search = 'Dragons';
    $results = GameSystem::where(function ($q) use ($search) {
        $trait = new class {
            use QueriesTranslatableColumns;
            public function testWhere(Builder $q, string $field, string $search): void
            {
                $this->whereTranslatableLike($q, $field, $search);
            }
        };
        $trait->testWhere($q, 'name', $search);
    })->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->getTranslation('name', 'en'))->toBe('Dungeons and Dragons 5e');
});
