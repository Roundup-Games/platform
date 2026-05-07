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
// LOGGING (policy is side-effect-free — no logging)
// ═══════════════════════════════════════════════════════════

describe('Share token logging', function () {
    it('does not log when share token grants access', function () {
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

        Log::shouldNotHaveReceived('info');
    });
});

// ═══════════════════════════════════════════════════════════
// STATUS CHECK — completed/canceled games deny share token bypass
// ═══════════════════════════════════════════════════════════

describe('Share token status guard', function () {
    it('denies access via share token for completed game', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'status' => 'completed',
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $game))->toBeFalse();
    });

    it('denies access via share token for canceled game', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'status' => 'canceled',
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $game))->toBeFalse();
    });

    it('grants access via share token for scheduled game', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'status' => 'scheduled',
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $game))->toBeTrue();
    });
});
