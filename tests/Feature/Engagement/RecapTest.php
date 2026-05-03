<?php

use App\Enums\ActivityType;
use App\Enums\ParticipantStatus;
use App\Models\ActivityLog;
use App\Models\Game;
use App\Models\GameParticipant;
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

    it('rejects non-host writing recap', function () {
        $other = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
        ]);

        expect(fn () => $this->service->writeRecap($game, $other, 'Nice game!'))
            ->toThrow(\LogicException::class);
    });

    it('rejects recap on non-completed game', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);

        expect(fn () => $this->service->writeRecap($game, $this->host, 'Not done yet'))
            ->toThrow(\LogicException::class);
    });

    it('rejects recap exceeding 2000 characters', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
        ]);

        $longContent = str_repeat('a', 2001);

        expect(fn () => $this->service->writeRecap($game, $this->host, $longContent))
            ->toThrow(\LogicException::class);
    });

    it('accepts recap at exactly 2000 characters', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
        ]);

        $content = str_repeat('a', 2000);
        $this->service->writeRecap($game, $this->host, $content);

        expect($game->fresh()->recap)->toBe($content);
    });

    it('rejects empty recap', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
        ]);

        expect(fn () => $this->service->writeRecap($game, $this->host, '   '))
            ->toThrow(\LogicException::class);
    });

    it('generates activity log entry on recap', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
        ]);

        $this->service->writeRecap($game, $this->host, 'Amazing session!');

        $log = ActivityLog::where('user_id', $this->host->id)
            ->where('subject_type', Game::class)
            ->where('subject_id', $game->id)
            ->where('event_type', ActivityType::SessionRecapped)
            ->first();

        expect($log)->not->toBeNull()
            ->and($log->properties['game_id'])->toBe($game->id)
            ->and($log->properties['author_id'])->toBe($this->host->id);
    });

    it('notifies approved participants when recap is written', function () {
        $participant = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $this->service->writeRecap($game, $this->host, 'Great session!');

        // Check the participant received a database notification
        $notification = $participant->notifications()->first();
        expect($notification)->not->toBeNull()
            ->and($notification->data['type'])->toBe('recap_posted')
            ->and($notification->data['entity_id'])->toBe($game->id);
    });

    it('does not notify host who wrote the recap', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
        ]);

        $this->service->writeRecap($game, $this->host, 'Solo recap');

        expect($this->host->notifications()->count())->toBe(0);
    });

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
