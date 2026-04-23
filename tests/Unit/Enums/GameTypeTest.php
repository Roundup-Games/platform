<?php

use App\Enums\GameType;

describe('GameType enum', function () {
    it('has the expected cases', function () {
        $cases = GameType::cases();

        expect($cases)->toHaveCount(2);
        expect($cases[0])->toBe(GameType::BoardGame);
        expect($cases[1])->toBe(GameType::Ttrpg);
    });

    it('returns correct values', function () {
        expect(GameType::values())->toBe(['board_game', 'ttrpg']);
    });

    it('returns display labels for each case', function () {
        expect(GameType::BoardGame->label())->toBe('Board Game');
        expect(GameType::Ttrpg->label())->toBe('TTRPG');
    });

    it('is backed by string type', function () {
        $reflection = new ReflectionEnum(GameType::class);
        expect($reflection->getBackingType()?->getName())->toBe('string');
    });

    it('can be instantiated from a string value', function () {
        expect(GameType::from('board_game'))->toBe(GameType::BoardGame);
        expect(GameType::from('ttrpg'))->toBe(GameType::Ttrpg);
    });
});
