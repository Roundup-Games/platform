<?php

use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Support\Edit\FieldTiers;

// Guards decision D313: FieldTiers is the ONLY place that decides which game
// and campaign fields are locked at first confirmation (Tier 1), editable
// with a notification (Tier 2) or freely editable (Tier 3).
//
// The inventories below are pinned here as literal arrays — keyed by the
// LITERAL tier value, never by FieldTiers::TIER_*, so that re-valuing a tier
// constant cannot silently re-tier the pin. They were verified against the
// validation rule keys of CreateGame / CreateCampaign and against the schema
// (M084/S01). A later edit to FieldTiers that drops, adds or re-tiers a
// field fails here and has to be made here too — deliberately.

/**
 * @return array<int, list<string>>
 */
function fieldTiersPinnedGameInventory(): array
{
    return [
        1 => [
            'game_systems', 'game_system_id', 'game_type', 'date_time', 'expected_duration',
            'price', 'min_players', 'bench_mode', 'language',
        ],
        2 => ['location_id', 'location', 'location_instructions', 'safety_rules'],
        3 => [
            'name', 'description', 'cover_image', 'experience_level', 'complexity',
            'minimum_requirements', 'min_reliability_preference', 'signup_cutoff_at',
            'host_note', 'vibe_flags',
        ],
    ];
}

/**
 * @return array<int, list<string>>
 */
function fieldTiersPinnedCampaignInventory(): array
{
    return [
        1 => [
            'game_systems', 'game_system_id', 'game_type', 'recurrence', 'time_of_day',
            'session_duration', 'price_per_session', 'min_players', 'bench_mode', 'language',
        ],
        2 => ['location_id', 'location_instructions', 'safety_rules'],
        3 => [
            'name', 'description', 'cover_image', 'experience_level', 'complexity',
            'minimum_requirements', 'host_note', 'vibe_flags',
        ],
    ];
}

/**
 * One [field, tier] row per pinned field, keyed by field. A field pinned
 * under two tiers collapses into one row, which the count assertions catch.
 *
 * @param  array<int, list<string>>  $inventory
 * @return array<string, array{string, int}>
 */
function fieldTiersPinnedRows(array $inventory): array
{
    $rows = [];

    foreach ($inventory as $tier => $fields) {
        foreach ($fields as $field) {
            $rows[$field] = [$field, $tier];
        }
    }

    return $rows;
}

describe('FieldTiers constants', function () {
    it('exposes the three consent tiers with the values of decision D313', function () {
        expect(FieldTiers::TIER_LOCKED)->toBe(1)
            ->and(FieldTiers::TIER_NOTIFIED)->toBe(2)
            ->and(FieldTiers::TIER_FREE)->toBe(3);
    });

    it('uses only those three values in both tier maps', function () {
        expect(array_values(FieldTiers::GAME_TIERS))->each->toBeIn([1, 2, 3])
            ->and(array_values(FieldTiers::CAMPAIGN_TIERS))->each->toBeIn([1, 2, 3]);
    });
});

describe('FieldTiers game inventory', function () {
    it('classifies every game field at its pinned tier', function (string $field, int $tier) {
        expect(FieldTiers::GAME_TIERS[$field])->toBe($tier)
            ->and(FieldTiers::tierOf(Game::class, $field))->toBe($tier);
    })->with(fieldTiersPinnedRows(fieldTiersPinnedGameInventory()));

    it('classifies exactly the pinned game fields — none missing, none extra', function () {
        $pinned = array_keys(fieldTiersPinnedRows(fieldTiersPinnedGameInventory()));
        $classified = array_keys(FieldTiers::GAME_TIERS);

        sort($pinned);
        sort($classified);

        expect($classified)->toBe(
            $pinned,
            'FieldTiers::GAME_TIERS drifted from the verified inventory. Adding, dropping or renaming a field is a deliberate consent decision: change the pinned inventory in this test in the same commit.',
        );
    });

    it('lists each game field under exactly one tier', function () {
        $pinned = array_merge(...array_values(fieldTiersPinnedGameInventory()));

        expect(count(fieldTiersPinnedRows(fieldTiersPinnedGameInventory())))->toBe(count($pinned))
            ->and(count(FieldTiers::GAME_TIERS))->toBe(count($pinned));
    });
});

describe('FieldTiers campaign inventory', function () {
    it('classifies every campaign field at its pinned tier', function (string $field, int $tier) {
        expect(FieldTiers::CAMPAIGN_TIERS[$field])->toBe($tier)
            ->and(FieldTiers::tierOf(Campaign::class, $field))->toBe($tier);
    })->with(fieldTiersPinnedRows(fieldTiersPinnedCampaignInventory()));

    it('classifies exactly the pinned campaign fields — none missing, none extra', function () {
        $pinned = array_keys(fieldTiersPinnedRows(fieldTiersPinnedCampaignInventory()));
        $classified = array_keys(FieldTiers::CAMPAIGN_TIERS);

        sort($pinned);
        sort($classified);

        expect($classified)->toBe(
            $pinned,
            'FieldTiers::CAMPAIGN_TIERS drifted from the verified inventory. Adding, dropping or renaming a field is a deliberate consent decision: change the pinned inventory in this test in the same commit.',
        );
    });

    it('lists each campaign field under exactly one tier', function () {
        $pinned = array_merge(...array_values(fieldTiersPinnedCampaignInventory()));

        expect(count(fieldTiersPinnedRows(fieldTiersPinnedCampaignInventory())))->toBe(count($pinned))
            ->and(count(FieldTiers::CAMPAIGN_TIERS))->toBe(count($pinned));
    });
});

describe('FieldTiers model differences', function () {
    it('refuses a game field that campaigns do not have — and the reverse', function () {
        $gameOnly = array_values(array_diff(array_keys(FieldTiers::GAME_TIERS), array_keys(FieldTiers::CAMPAIGN_TIERS)));
        $campaignOnly = array_values(array_diff(array_keys(FieldTiers::CAMPAIGN_TIERS), array_keys(FieldTiers::GAME_TIERS)));

        // Campaigns recur instead of meeting once (recurrence + time_of_day,
        // no date_time), price per session, and store their length as
        // session_duration; they have no JSON location, no signup cutoff and
        // no reliability preference.
        expect($gameOnly)->toEqualCanonicalizing(['date_time', 'expected_duration', 'location', 'min_reliability_preference', 'price', 'signup_cutoff_at'])
            ->and($campaignOnly)->toEqualCanonicalizing(['price_per_session', 'recurrence', 'session_duration', 'time_of_day']);

        foreach ($gameOnly as $field) {
            expect(fn () => FieldTiers::tierOf(Campaign::class, $field))->toThrow(InvalidArgumentException::class, 'is not classified');
        }

        foreach ($campaignOnly as $field) {
            expect(fn () => FieldTiers::tierOf(Game::class, $field))->toThrow(InvalidArgumentException::class, 'is not classified');
        }
    });
});

describe('FieldTiers unclassified input', function () {
    it('throws for a field that is not classified for the model', function (string $modelClass, string $field) {
        expect(fn () => FieldTiers::tierOf($modelClass, $field))->toThrow(InvalidArgumentException::class, 'is not classified');
    })->with([
        'a column added after the inventory was written' => [Game::class, 'some_future_column'],
        'a system-managed column' => [Game::class, 'status'],
        'the owner' => [Campaign::class, 'owner_id'],
        'comfort_notes, which lives inside safety_rules' => [Game::class, 'comfort_notes'],
        'a nested rule key' => [Game::class, 'safety_rules.tools'],
        'the empty string' => [Game::class, ''],
    ]);

    it('explains where comfort_notes lives instead of leaving the caller to guess', function () {
        expect(fn () => FieldTiers::tierOf(Game::class, 'comfort_notes'))
            ->toThrow(InvalidArgumentException::class, 'store it inside the safety_rules JSON, so ask for safety_rules');
    });

    it('throws for a class that is neither Game nor Campaign', function (string $modelClass) {
        expect(fn () => FieldTiers::tierOf($modelClass, 'name'))->toThrow(InvalidArgumentException::class, 'Unknown model class');
    })->with([
        'another model' => [User::class],
        'the participant model' => [GameParticipant::class],
        'a class that does not exist' => ['App\Models\DoesNotExist'],
        'a bare word' => ['nonsense'],
    ]);
});

describe('direction-dependent fields', function () {
    it('keeps max_players and visibility out of both flat maps', function () {
        expect(FieldTiers::GAME_TIERS)->not->toHaveKeys(['max_players', 'visibility'])
            ->and(FieldTiers::CAMPAIGN_TIERS)->not->toHaveKeys(['max_players', 'visibility']);
    });

    it('names both of them, and only them, in DIRECTION_DEPENDENT', function () {
        expect(array_keys(FieldTiers::DIRECTION_DEPENDENT))->toEqualCanonicalizing(['max_players', 'visibility']);
    });

    it('refuses them in tierOf() and points at the resolver that needs the direction', function (string $modelClass) {
        expect(fn () => FieldTiers::tierOf($modelClass, 'max_players'))
            ->toThrow(InvalidArgumentException::class, FieldTiers::class.'::maxPlayersTier()');

        expect(fn () => FieldTiers::tierOf($modelClass, 'visibility'))
            ->toThrow(InvalidArgumentException::class, FieldTiers::class.'::visibilityTier()');
    })->with([
        'game' => [Game::class],
        'campaign' => [Campaign::class],
    ]);
});

describe('maxPlayersTier', function () {
    it('tiers a max_players change by direction', function (int $current, int $new, int $tier) {
        expect(FieldTiers::maxPlayersTier($current, $new))->toBe($tier);
    })->with([
        'decrease' => [6, 4, 1],
        'decrease by one' => [6, 5, 1],
        'decrease to zero' => [2, 0, 1],
        'increase' => [6, 8, 2],
        'increase by one' => [6, 7, 2],
        'increase from zero' => [0, 1, 2],
        'equal' => [6, 6, 3],
        'equal at zero' => [0, 0, 3],
    ]);
});

describe('visibilityTier', function () {
    it('tiers a visibility change by direction', function (string $current, string $new, int $tier) {
        expect(FieldTiers::visibilityTier($current, $new))->toBe($tier);
    })->with([
        'public to private' => ['public', 'private', 1],
        'private to public' => ['private', 'public', 3],
        'protected to private' => ['protected', 'private', 3],
        'public to protected' => ['public', 'protected', 3],
        'protected to public' => ['protected', 'public', 3],
        'private to protected' => ['private', 'protected', 3],
        'public, unchanged' => ['public', 'public', 3],
        'protected, unchanged' => ['protected', 'protected', 3],
        'private, unchanged' => ['private', 'private', 3],
    ]);

    it('locks exactly one transition — public to private', function () {
        $values = Visibility::values();

        expect($values)->toBe(['public', 'protected', 'private']);

        $locked = [];

        foreach ($values as $current) {
            foreach ($values as $new) {
                if (FieldTiers::visibilityTier($current, $new) === 1) {
                    $locked[] = $current.' to '.$new;
                }
            }
        }

        expect($locked)->toBe(['public to private']);
    });

    it('throws for a value that is not a visibility instead of calling the change free', function (string $current, string $new) {
        expect(fn () => FieldTiers::visibilityTier($current, $new))
            ->toThrow(InvalidArgumentException::class, 'Unknown visibility value');
    })->with([
        'unknown target' => ['public', 'hidden'],
        'unknown origin' => ['hidden', 'private'],
        'both unknown' => ['', ''],
        'case does not match the stored value' => ['Public', 'private'],
    ]);
});
