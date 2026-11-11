<?php

namespace Tests\Feature\Services;

use App\Dto\CreateDefaults;
use App\Enums\GameStatus;
use App\Enums\GameType;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use App\Services\CreateDefaultsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

// Direct coverage of the smart-defaults resolution service (D169d). The
// CreateGame/CreateCampaign Livewire forms call forGameType()/forCampaign()
// during mount and layer their own per-field fallbacks on top, so this suite
// pins the resolution contracts themselves: history scoping (same type,
// owned, newest), field carry-forward with scalar coercion, and the
// profile-preference fallbacks (location, type-filtered favorites).
//
// The service is read-only resolution — no writes, no authorization (the
// forms gate both) — so every contract here is a returned-DTO assertion.

/**
 * A game owned by $owner, persisted with an explicit offered-system set.
 */
function createDefaultsGame(User $owner, array $attributes = [], array $systemIds = []): Game
{
    $factory = Game::factory();

    if ($systemIds === []) {
        $factory = $factory->withoutGameSystems();
    } else {
        $factory = $factory->withGameSystems($systemIds);
    }

    return $factory->create(array_merge(['owner_id' => $owner->id], $attributes));
}

describe('forGameType - history scoping', function () {
    it('returns all-null defaults for a host with no game history', function () {
        $defaults = app(CreateDefaultsService::class)->forGameType(User::factory()->create(), GameType::Ttrpg);

        expect(get_object_vars($defaults))->toEqual([
            'language' => null,
            'locationId' => null,
            'locationInstructions' => null,
            'visibility' => null,
            'experienceLevel' => null,
            'expectedDuration' => null,
            'maxPlayers' => null,
            'minPlayers' => null,
            'gameSystemId' => null,
            'gameSystems' => [],
            'gameType' => null,
            'recurrence' => null,
            'vibeFlags' => null,
        ]);
    });

    it('ignores sessions of a different game type', function () {
        $host = User::factory()->create();
        createDefaultsGame($host, ['game_type' => GameType::BoardGame->value]);

        $defaults = app(CreateDefaultsService::class)->forGameType($host, GameType::Ttrpg);

        expect($defaults)->toEqual(new CreateDefaults);
    });

    it('ignores games the host does not own', function () {
        $host = User::factory()->create();
        createDefaultsGame(User::factory()->create(), ['game_type' => GameType::Ttrpg->value]);

        $defaults = app(CreateDefaultsService::class)->forGameType($host, GameType::Ttrpg);

        expect($defaults)->toEqual(new CreateDefaults);
    });
});

describe('forGameType - carry-forward', function () {
    it('carries the most recent same-type session forward as coerced scalars', function () {
        $host = User::factory()->create();
        $system = GameSystem::factory()->create();
        $location = Location::factory()->create();

        // A completed session still counts — history is scoped by type and
        // ownership, not status.
        createDefaultsGame($host, [
            'game_type' => GameType::Ttrpg->value,
            'status' => GameStatus::Completed->value,
            'language' => 'de',
            'location_id' => $location->id,
            'location_instructions' => 'Ring the bell twice',
            'visibility' => 'private',
            'experience_level' => 'advanced',
            'expected_duration' => 3.5,
            'max_players' => 5,
            'min_players' => 3,
            'vibe_flags' => ['atmospheric'],
        ], [$system->id]);

        $defaults = app(CreateDefaultsService::class)->forGameType($host, GameType::Ttrpg);

        // Eloquent's enum/float attributes are coerced to the DTO's scalar
        // types — the forms assign these directly to typed Livewire props.
        expect($defaults->language)->toBeString()->toBe('de');
        expect($defaults->locationId)->toBe($location->id);
        expect($defaults->locationInstructions)->toBe('Ring the bell twice');
        expect($defaults->visibility)->toBeString()->toBe('private');
        expect($defaults->experienceLevel)->toBe('advanced');
        expect($defaults->expectedDuration)->toBeString()->toBe('3.5');
        expect($defaults->maxPlayers)->toBe(5);
        expect($defaults->minPlayers)->toBe(3);
        expect($defaults->gameSystemId)->toBeString()->toBe($system->id);
        expect($defaults->gameSystems)->toBe([$system->id]);
        expect($defaults->vibeFlags)->toBe(['atmospheric']);
    });

    it('prefers the newest same-type session by date_time', function () {
        $host = User::factory()->create();

        createDefaultsGame($host, [
            'game_type' => GameType::BoardGame->value,
            'language' => 'de',
            'max_players' => 4,
            'date_time' => now()->subDays(10),
        ]);
        createDefaultsGame($host, [
            'game_type' => GameType::BoardGame->value,
            'language' => 'en',
            'max_players' => 7,
            'date_time' => now()->subDays(2),
        ]);

        $defaults = app(CreateDefaultsService::class)->forGameType($host, GameType::BoardGame);

        expect($defaults->language)->toBe('en');
        expect($defaults->maxPlayers)->toBe(7);
    });

    it('carries the full offered-system set forward for gatherings', function () {
        $host = User::factory()->create();
        $first = GameSystem::factory()->create();
        $second = GameSystem::factory()->create();

        createDefaultsGame($host, [
            'game_type' => GameType::Gathering->value,
        ], [$first->id, $second->id]);

        $defaults = app(CreateDefaultsService::class)->forGameType($host, GameType::Gathering);

        expect($defaults->gameSystems)->toEqualCanonicalizing([$first->id, $second->id]);
        expect($defaults->gameSystemId)->toBeIn([$first->id, $second->id]);
    });

    it('collapses an empty vibe flag list to null', function (array $flagsOnGame, ?array $expected) {
        $host = User::factory()->create();

        createDefaultsGame($host, [
            'game_type' => GameType::Ttrpg->value,
            'vibe_flags' => $flagsOnGame,
        ]);

        $defaults = app(CreateDefaultsService::class)->forGameType($host, GameType::Ttrpg);

        expect($defaults->vibeFlags)->toBe($expected);
    })->with([
        'no vibe flags' => [[], null],
        'flags present' => [['atmospheric', 'rules-light'], ['atmospheric', 'rules-light']],
    ]);

    it('falls back to the profile location when the last session had none', function () {
        $host = User::factory()->create(['location_id' => Location::factory()]);

        createDefaultsGame($host, ['game_type' => GameType::Ttrpg->value]);

        $defaults = app(CreateDefaultsService::class)->forGameType($host, GameType::Ttrpg);

        expect($defaults->locationId)->toBe($host->location_id);
    });
});

describe('forGameType - favorite fallback', function () {
    it('falls back to the type-matching favorite game system when the session offers none', function (GameType $ask, string $expectedFavoriteType) {
        $host = User::factory()->create();

        $boardgameFavorite = GameSystem::factory()->create(['type' => 'boardgame']);
        $ttrpgFavorite = GameSystem::factory()->create(['type' => 'ttrpg']);
        $host->favoriteGameSystems()->attach($boardgameFavorite->id, ['preference_type' => 'favorite']);
        $host->favoriteGameSystems()->attach($ttrpgFavorite->id, ['preference_type' => 'favorite']);

        createDefaultsGame($host, ['game_type' => $ask->value]);

        $defaults = app(CreateDefaultsService::class)->forGameType($host, $ask);

        $expected = $expectedFavoriteType === 'boardgame' ? $boardgameFavorite : $ttrpgFavorite;
        expect($defaults->gameSystemId)->toBe($expected->id);
    })->with([
        'board game form resolves the boardgame favorite' => [GameType::BoardGame, 'boardgame'],
        'ttrpg form resolves the ttrpg favorite' => [GameType::Ttrpg, 'ttrpg'],
        // A TTRPG favorite must never leak into a board-game night form and
        // vice versa — gatherings borrow the board-game pool.
        'gathering form resolves the boardgame favorite' => [GameType::Gathering, 'boardgame'],
    ]);
});

describe('forCampaign', function () {
    it('returns all-null defaults for a host with no game history', function () {
        $defaults = app(CreateDefaultsService::class)->forCampaign(User::factory()->create());

        expect($defaults)->toEqual(new CreateDefaults);
    });

    it('prefers an older campaign session over a newer standalone game', function () {
        $host = User::factory()->create();
        $campaign = Campaign::factory()->create();

        createDefaultsGame($host, [
            'campaign_id' => $campaign->id,
            'game_type' => GameType::Ttrpg->value,
            'language' => 'de',
            'max_players' => 4,
            'visibility' => 'private',
            'date_time' => now()->subDays(10),
        ]);
        createDefaultsGame($host, [
            'game_type' => GameType::BoardGame->value,
            'language' => 'en',
            'max_players' => 7,
            'visibility' => 'public',
            'date_time' => now()->subDays(2),
        ]);

        $defaults = app(CreateDefaultsService::class)->forCampaign($host);

        expect($defaults->language)->toBe('de');
        expect($defaults->maxPlayers)->toBe(4);
        expect($defaults->visibility)->toBe('private');
    });

    it('falls back to the newest standalone game with weekly recurrence', function () {
        $host = User::factory()->create();
        $system = GameSystem::factory()->create();

        createDefaultsGame($host, [
            'game_type' => GameType::BoardGame->value,
            'max_players' => 6,
            'date_time' => now()->subDays(10),
        ]);
        createDefaultsGame($host, [
            'game_type' => GameType::Ttrpg->value,
            'max_players' => 3,
            'date_time' => now()->subDays(2),
        ], [$system->id]);

        $defaults = app(CreateDefaultsService::class)->forCampaign($host);

        expect($defaults->gameType)->toBeString()->toBe('ttrpg');
        expect($defaults->maxPlayers)->toBe(3);
        expect($defaults->gameSystemId)->toBe($system->id);
        expect($defaults->recurrence)->toBe('weekly');
    });

    it('falls back to the ttrpg favorite pool when the source session offers no system', function (string $favoriteType) {
        $host = User::factory()->create();
        $campaign = Campaign::factory()->create();

        $favorite = GameSystem::factory()->create(['type' => $favoriteType]);
        $host->favoriteGameSystems()->attach($favorite->id, ['preference_type' => 'favorite']);

        createDefaultsGame($host, [
            'campaign_id' => $campaign->id,
            'game_type' => GameType::Ttrpg->value,
        ]);

        $defaults = app(CreateDefaultsService::class)->forCampaign($host);

        // The fallback is pinned to the ttrpg favorite pool regardless of
        // the campaign's own system type — a boardgame favorite never leaks in.
        expect($defaults->gameSystemId)->toBe($favoriteType === 'ttrpg' ? $favorite->id : null);
    })->with([
        'ttrpg favorite is picked' => ['ttrpg'],
        'boardgame favorite is ignored' => ['boardgame'],
    ]);
});
