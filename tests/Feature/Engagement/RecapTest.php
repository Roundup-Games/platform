<?php

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\RecapService;
use Illuminate\Support\Facades\Log;

describe('Recap Service', function () {
    beforeEach(function () {
        $this->host = User::factory()->create(['profile_complete' => true]);
        $this->gameSystem = GameSystem::factory()->create();
        $this->service = app(RecapService::class);
    });

    // smoke: host writes recap on completed game
    it('allows host to write recap on completed game', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
        ]);

        $this->service->writeRecap($game, $this->host, 'Great session with epic roleplay moments!');

        expect($game->fresh()->recap)->toBe('Great session with epic roleplay moments!');
    })->group('smoke');

    it('prevents overwriting an existing recap', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'recap' => 'Original recap content',
        ]);

        expect(fn () => $this->service->writeRecap($game, $this->host, 'New recap content'))
            ->toThrow(\LogicException::class);
    });
});
