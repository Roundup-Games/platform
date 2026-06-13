<?php

use App\Services\PlatformScoreService;

beforeEach(function () {
    $this->service = new PlatformScoreService;
});

describe('calculateScore — pure formula', function () {
    it('computes correct score with known boardgame counts', function () {
        // favorites=5, games=10, campaigns=3, activeGames=2
        // boardgame weights: favorites=10, games=3, campaigns=5, active_games=20
        // expected = 5*10 + 10*3 + 3*5 + 2*20 = 50 + 30 + 15 + 40 = 135
        $score = $this->service->calculateScore(5, 10, 3, 2, 'boardgame');

        expect($score)->toBe(135);
    });

    it('computes correct score with known ttrpg counts', function () {
        // same counts, different weights
        // ttrpg weights: favorites=10, games=3, campaigns=15, active_games=10
        // expected = 5*10 + 10*3 + 3*15 + 2*10 = 50 + 30 + 45 + 20 = 145
        $score = $this->service->calculateScore(5, 10, 3, 2, 'ttrpg');

        expect($score)->toBe(145);
    });

    it('produces different scores for boardgame vs ttrpg with same counts', function () {
        $boardgame = $this->service->calculateScore(5, 10, 3, 2, 'boardgame');
        $ttrpg = $this->service->calculateScore(5, 10, 3, 2, 'ttrpg');

        expect($boardgame)->not->toBe($ttrpg);
    });

    it('falls back to boardgame weights for null type', function () {
        $explicit = $this->service->calculateScore(5, 10, 3, 2, 'boardgame');
        $null = $this->service->calculateScore(5, 10, 3, 2, null);

        expect($null)->toBe($explicit);
    });

    it('falls back to boardgame weights for unknown type', function () {
        $explicit = $this->service->calculateScore(5, 10, 3, 2, 'boardgame');
        $unknown = $this->service->calculateScore(5, 10, 3, 2, 'wargame');

        expect($unknown)->toBe($explicit);
    });

    it('returns zero when all counts are zero', function () {
        expect($this->service->calculateScore(0, 0, 0, 0, 'boardgame'))->toBe(0);
        expect($this->service->calculateScore(0, 0, 0, 0, 'ttrpg'))->toBe(0);
    });

    it('returns non-zero score when only favorites > 0', function () {
        // boardgame: 1 * 10 = 10
        expect($this->service->calculateScore(1, 0, 0, 0, 'boardgame'))->toBe(10);
        // ttrpg: 1 * 10 = 10
        expect($this->service->calculateScore(1, 0, 0, 0, 'ttrpg'))->toBe(10);
    });

    it('returns non-zero score when only games > 0', function () {
        expect($this->service->calculateScore(0, 1, 0, 0, 'boardgame'))->toBe(3);
    });

    it('returns non-zero score when only campaigns > 0', function () {
        // boardgame: 1 * 5 = 5; ttrpg: 1 * 15 = 15
        expect($this->service->calculateScore(0, 0, 1, 0, 'boardgame'))->toBe(5);
        expect($this->service->calculateScore(0, 0, 1, 0, 'ttrpg'))->toBe(15);
    });

    it('returns non-zero score when only active games > 0', function () {
        // boardgame: 1 * 20 = 20; ttrpg: 1 * 10 = 10
        expect($this->service->calculateScore(0, 0, 0, 1, 'boardgame'))->toBe(20);
        expect($this->service->calculateScore(0, 0, 0, 1, 'ttrpg'))->toBe(10);
    });
});

describe('weight constants — regression guard', function () {
    it('has boardgame weight profile with expected keys and values', function () {
        $weights = $this->service->getWeights();

        expect($weights)->toHaveKey('boardgame');
        expect($weights['boardgame'])->toBe([
            'favorites' => 10,
            'games' => 3,
            'campaigns' => 5,
            'active_games' => 20,
        ]);
    });

    it('has ttrpg weight profile with expected keys and values', function () {
        $weights = $this->service->getWeights();

        expect($weights)->toHaveKey('ttrpg');
        expect($weights['ttrpg'])->toBe([
            'favorites' => 10,
            'games' => 3,
            'campaigns' => 15,
            'active_games' => 10,
        ]);
    });
});
