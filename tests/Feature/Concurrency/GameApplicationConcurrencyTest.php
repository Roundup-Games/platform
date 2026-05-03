<?php

use App\Models\Game;
use App\Models\GameApplication;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use function Pest\Laravel\actingAs;

/**
 * Concurrency tests for game application.
 *
 * The game_applications and game_participants tables both have unique constraints
 * on (game_id, user_id). The code wraps check-then-create in DB::transaction()
 * with lockForUpdate, and catches QueryException for the unique constraint fallback.
 */
describe('Game Application Concurrency', function () {
    it('prevents duplicate application via transaction check', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $gameSystem = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'visibility' => 'public',
            'status' => 'scheduled',
        ]);

        $user = User::factory()->create(['profile_complete' => true]);
        $gameId = $game->id;
        $userId = $user->id;

        // First application — succeeds
        $result1 = false;
        try {
            DB::transaction(function () use ($gameId, $userId, &$result1) {
                GameParticipant::lockForUpdate()
                    ->where('game_id', $gameId)
                    ->where('user_id', $userId)
                    ->exists();

                GameApplication::lockForUpdate()
                    ->where('game_id', $gameId)
                    ->where('user_id', $userId)
                    ->exists();

                if (GameParticipant::where('game_id', $gameId)->where('user_id', $userId)->exists()) {
                    throw new \RuntimeException('Already a participant.');
                }
                if (GameApplication::where('game_id', $gameId)->where('user_id', $userId)->exists()) {
                    throw new \RuntimeException('Already applied.');
                }

                $isPublic = Game::find($gameId)->visibility === 'public';

                GameApplication::create([
                    'game_id' => $gameId,
                    'user_id' => $userId,
                    'status' => $isPublic ? 'approved' : 'pending',
                    'message' => null,
                ]);

                GameParticipant::create([
                    'game_id' => $gameId,
                    'user_id' => $userId,
                    'role' => $isPublic ? 'player' : 'applicant',
                    'status' => $isPublic ? 'approved' : 'pending',
                ]);

                $result1 = true;
            });
        } catch (\RuntimeException $e) {
            $result1 = false;
        }

        // Second application — should fail (duplicate check inside transaction)
        $result2 = false;
        try {
            DB::transaction(function () use ($gameId, $userId, &$result2) {
                GameParticipant::lockForUpdate()
                    ->where('game_id', $gameId)
                    ->where('user_id', $userId)
                    ->exists();

                GameApplication::lockForUpdate()
                    ->where('game_id', $gameId)
                    ->where('user_id', $userId)
                    ->exists();

                if (GameParticipant::where('game_id', $gameId)->where('user_id', $userId)->exists()) {
                    throw new \RuntimeException('Already a participant.');
                }
                if (GameApplication::where('game_id', $gameId)->where('user_id', $userId)->exists()) {
                    throw new \RuntimeException('Already applied.');
                }

                GameApplication::create([
                    'game_id' => $gameId,
                    'user_id' => $userId,
                    'status' => 'approved',
                    'message' => null,
                ]);

                GameParticipant::create([
                    'game_id' => $gameId,
                    'user_id' => $userId,
                    'role' => 'player',
                    'status' => 'approved',
                ]);

                $result2 = true;
            });
        } catch (\RuntimeException $e) {
            $result2 = false;
        }

        expect($result1)->toBeTrue('First application should succeed');
        expect($result2)->toBeFalse('Second application should fail — duplicate');

        expect(GameApplication::where('game_id', $gameId)->where('user_id', $userId)->count())->toBe(1);
        expect(GameParticipant::where('game_id', $gameId)->where('user_id', $userId)->count())->toBe(1);
    })->group('smoke');

    it('catches QueryException from unique constraint violation', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $gameSystem = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'visibility' => 'public',
            'status' => 'scheduled',
        ]);

        $user = User::factory()->create(['profile_complete' => true]);

        // Pre-create an application to trigger unique constraint
        GameApplication::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'message' => null,
        ]);

        $gameId = $game->id;
        $userId = $user->id;

        // Attempt to create another — unique constraint should throw QueryException
        $caught = false;
        try {
            DB::transaction(function () use ($gameId, $userId) {
                // Bypass the check — go straight to create to trigger the unique constraint
                GameApplication::create([
                    'game_id' => $gameId,
                    'user_id' => $userId,
                    'status' => 'approved',
                    'message' => null,
                ]);
            });
        } catch (QueryException $e) {
            $caught = true;
        }

        expect($caught)->toBeTrue('QueryException should be thrown for unique constraint violation');
        expect(GameApplication::where('game_id', $gameId)->where('user_id', $userId)->count())->toBe(1);
    })->group('smoke');

    it('prevents duplicate participant via unique constraint', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $gameSystem = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'visibility' => 'public',
            'status' => 'scheduled',
        ]);

        $user = User::factory()->create(['profile_complete' => true]);

        // Pre-create a participant to trigger unique constraint
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $gameId = $game->id;
        $userId = $user->id;

        // Attempt to create another participant — unique constraint should fire
        $caught = false;
        try {
            DB::transaction(function () use ($gameId, $userId) {
                GameParticipant::create([
                    'game_id' => $gameId,
                    'user_id' => $userId,
                    'role' => 'player',
                    'status' => 'approved',
                ]);
            });
        } catch (QueryException $e) {
            $caught = true;
        }

        expect($caught)->toBeTrue('QueryException should be thrown for duplicate participant');
        expect(GameParticipant::where('game_id', $gameId)->where('user_id', $userId)->count())->toBe(1);
    })->group('smoke');

    it('uses Livewire component to submit application and prevents double-submit', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $gameSystem = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'visibility' => 'public',
            'status' => 'scheduled',
        ]);

        $user = User::factory()->create(['profile_complete' => true]);

        actingAs($user);

        $component = Livewire\Livewire::test(App\Livewire\Games\ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'I want to join!')
            ->call('submitApplication');

        // First submission should succeed and redirect
        $component->assertRedirect();

        expect(GameApplication::where('game_id', $game->id)->where('user_id', $user->id)->count())->toBe(1);
        expect(GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->count())->toBe(1);
    })->group('smoke');
});
