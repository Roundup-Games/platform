<?php

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    seedPermissions();
    seedRoles();

    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

// ═══════════════════════════════════════════════════════════
// PROTECTED GAME + VALID SHARE TOKEN
// ═══════════════════════════════════════════════════════════

describe('Protected game with share token', function () {
    it('grants guest access with valid share token', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $game))->toBeTrue();
    });

    it('grants stranger access with valid share token', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
        ]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger);
        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $game))->toBeTrue();
    });

    it('denies access with wrong token', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
        ]);

        request()->merge(['share' => (string) Str::uuid()]);

        expect(Gate::allows('view', $game))->toBeFalse();
    });

    it('denies access with expired token', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
            'share_token' => $token,
            'share_token_expires_at' => now()->subDay(),
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $game))->toBeFalse();
    });

    it('denies access when no token provided', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
        ]);

        // No share param in request
        expect(Gate::allows('view', $game))->toBeFalse();
    });

    it('denies access when game has no share_token set', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
            'share_token' => null,
            'share_token_expires_at' => null,
        ]);

        request()->merge(['share' => (string) Str::uuid()]);

        expect(Gate::allows('view', $game))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// PRIVATE GAME + VALID SHARE TOKEN
// ═══════════════════════════════════════════════════════════

describe('Private game with share token', function () {
    it('grants guest access with valid share token', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $game))->toBeTrue();
    });

    it('denies access with expired token on private game', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
            'share_token' => $token,
            'share_token_expires_at' => now()->subHour(),
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $game))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// TOKEN WITHOUT EXPIRY (never expires)
// ═══════════════════════════════════════════════════════════

describe('Share token without expiry', function () {
    it('grants access when token has no expiry date', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
            'share_token' => $token,
            'share_token_expires_at' => null,
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $game))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// LOGGING
// ═══════════════════════════════════════════════════════════

describe('Share token logging', function () {
    it('logs when share token grants access', function () {
        Log::spy();

        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
        ]);

        request()->merge(['share' => $token]);

        Gate::allows('view', $game);

        $tokenPrefix = substr($token, 0, 8);
        Log::shouldHaveReceived('info')
            ->with('Share token granted access', \Mockery::on(function ($context) use ($game, $tokenPrefix) {
                return $context['entity_type'] === 'game'
                    && $context['entity_id'] === $game->id
                    && str_starts_with($context['share_token'], $tokenPrefix);
            }));
    });
});
