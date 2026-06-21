<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

beforeEach(function () {
    seedPermissions();
    seedRoles();

    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

// Helper to create test entities with shared attributes
function createShareableEntity(string $type, array $overrides = []): array
{
    $owner = test()->owner;
    $gameSystem = test()->gameSystem;

    $base = [
        'owner_id' => $owner->id,
        'game_system_id' => $gameSystem->id,
    ];

    if ($type === 'game') {
        return ['entity' => Game::factory()->create([...$base, ...$overrides]), 'label' => 'game'];
    }

    return ['entity' => Campaign::factory()->create([...$base, ...$overrides]), 'label' => 'campaign'];
}

// ═══════════════════════════════════════════════════════════
// PROTECTED ENTITY + VALID SHARE TOKEN
// ═══════════════════════════════════════════════════════════

describe('Protected entity with share token', function () {
    it('grants guest access with valid share token', function ($type) {
        $token = (string) Str::uuid();
        ['entity' => $entity] = createShareableEntity($type, [
            'visibility' => 'protected',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $entity))->toBeTrue();
    })->with(['game', 'campaign']);

    it('grants stranger access with valid share token', function ($type) {
        $token = (string) Str::uuid();
        ['entity' => $entity] = createShareableEntity($type, [
            'visibility' => 'protected',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
        ]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger);
        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $entity))->toBeTrue();
    })->with(['game', 'campaign']);

    it('denies access with wrong token', function ($type) {
        $token = (string) Str::uuid();
        ['entity' => $entity] = createShareableEntity($type, [
            'visibility' => 'protected',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
        ]);

        request()->merge(['share' => (string) Str::uuid()]);

        expect(Gate::allows('view', $entity))->toBeFalse();
    })->with(['game', 'campaign']);

    it('denies access with expired token', function ($type) {
        $token = (string) Str::uuid();
        ['entity' => $entity] = createShareableEntity($type, [
            'visibility' => 'protected',
            'share_token' => $token,
            'share_token_expires_at' => now()->subDay(),
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $entity))->toBeFalse();
    })->with(['game', 'campaign']);

    it('denies access when no token provided', function ($type) {
        $token = (string) Str::uuid();
        ['entity' => $entity] = createShareableEntity($type, [
            'visibility' => 'protected',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
        ]);

        // No share param in request
        expect(Gate::allows('view', $entity))->toBeFalse();
    })->with(['game', 'campaign']);

    it('denies access when entity has no share_token set', function ($type) {
        ['entity' => $entity] = createShareableEntity($type, [
            'visibility' => 'protected',
            'share_token' => null,
            'share_token_expires_at' => null,
        ]);

        request()->merge(['share' => (string) Str::uuid()]);

        expect(Gate::allows('view', $entity))->toBeFalse();
    })->with(['game', 'campaign']);
});

// ═══════════════════════════════════════════════════════════
// PRIVATE ENTITY + VALID SHARE TOKEN
// ═══════════════════════════════════════════════════════════

describe('Private entity with share token', function () {
    it('grants guest access with valid share token', function ($type) {
        $token = (string) Str::uuid();
        ['entity' => $entity] = createShareableEntity($type, [
            'visibility' => 'private',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $entity))->toBeTrue();
    })->with(['game', 'campaign']);
});

// ═══════════════════════════════════════════════════════════
// TOKEN WITHOUT EXPIRY (never expires)
// ═══════════════════════════════════════════════════════════

describe('Share token without expiry', function () {
    it('grants access when token has no expiry date', function ($type) {
        $token = (string) Str::uuid();
        ['entity' => $entity] = createShareableEntity($type, [
            'visibility' => 'protected',
            'share_token' => $token,
            'share_token_expires_at' => null,
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $entity))->toBeTrue();
    })->with(['game', 'campaign']);
});

// ═══════════════════════════════════════════════════════════
// STATUS CHECK — terminal statuses deny share token bypass
// ═══════════════════════════════════════════════════════════

describe('Share token status guard', function () {
    it('denies access via share token for completed entity', function ($type) {
        $token = (string) Str::uuid();
        ['entity' => $entity] = createShareableEntity($type, [
            'visibility' => 'private',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'status' => 'completed',
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $entity))->toBeFalse();
    })->with(['game', 'campaign']);

    it('denies access via share token for canceled entity', function ($type) {
        $token = (string) Str::uuid();
        ['entity' => $entity] = createShareableEntity($type, [
            'visibility' => 'private',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'status' => $type === 'game' ? 'canceled' : 'cancelled',
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $entity))->toBeFalse();
    })->with(['game', 'campaign']);

    it('grants access via share token for active entity', function ($type) {
        $token = (string) Str::uuid();
        ['entity' => $entity] = createShareableEntity($type, [
            'visibility' => 'private',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'status' => $type === 'game' ? 'scheduled' : 'active',
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $entity))->toBeTrue();
    })->with(['game', 'campaign']);
});
