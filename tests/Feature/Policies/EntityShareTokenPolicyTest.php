<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/*
 * M077/S01 consolidation: 11 dataset templates × 2 entity types (22 cases,
 * each with its own boot) → 3 flow tests that loop the entity type in-test.
 * Every original template is preserved verbatim as a labeled step; per-case
 * entity creation is unchanged. The stranger-grant case runs last in each
 * iteration because actingAs() persists within a test — each iteration
 * opens with auth()->forgetGuards() so the guest steps stay guest.
 */

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

describe('Share token grants (flow)', function () {
    it('a valid share token grants access across entity types, visibilities, and lifecycles', function () {
        foreach (['game', 'campaign'] as $type) {
            // actingAs() persists for the rest of the test — the stranger
            // step at the END of the previous iteration leaves an
            // authenticated user on the guard. Reset to guest so every
            // iteration's "guest access" steps run as an actual guest
            // (the old suite proved the guest branch for BOTH entity types).
            auth()->forgetGuards();

            // grants guest access with valid share token (protected)
            $token = (string) Str::uuid();
            ['entity' => $entity] = createShareableEntity($type, [
                'visibility' => 'protected',
                'share_token' => $token,
                'share_token_expires_at' => now()->addDays(7),
            ]);
            request()->merge(['share' => $token]);
            expect(Gate::allows('view', $entity))->toBeTrue();

            // grants guest access with valid share token (private)
            $privateToken = (string) Str::uuid();
            ['entity' => $privateEntity] = createShareableEntity($type, [
                'visibility' => 'private',
                'share_token' => $privateToken,
                'share_token_expires_at' => now()->addDays(7),
            ]);
            request()->merge(['share' => $privateToken]);
            expect(Gate::allows('view', $privateEntity))->toBeTrue();

            // grants access when token has no expiry date (never expires)
            $noExpiryToken = (string) Str::uuid();
            ['entity' => $noExpiryEntity] = createShareableEntity($type, [
                'visibility' => 'protected',
                'share_token' => $noExpiryToken,
                'share_token_expires_at' => null,
            ]);
            request()->merge(['share' => $noExpiryToken]);
            expect(Gate::allows('view', $noExpiryEntity))->toBeTrue();

            // grants access via share token for active entity
            $activeToken = (string) Str::uuid();
            ['entity' => $activeEntity] = createShareableEntity($type, [
                'visibility' => 'private',
                'share_token' => $activeToken,
                'share_token_expires_at' => now()->addDays(7),
                'status' => $type === 'game' ? 'scheduled' : 'active',
            ]);
            request()->merge(['share' => $activeToken]);
            expect(Gate::allows('view', $activeEntity))->toBeTrue();

            // grants stranger access with valid share token (actingAs persists — last)
            $strangerToken = (string) Str::uuid();
            ['entity' => $strangerEntity] = createShareableEntity($type, [
                'visibility' => 'protected',
                'share_token' => $strangerToken,
                'share_token_expires_at' => now()->addDays(7),
            ]);
            $this->actingAs(User::factory()->create());
            request()->merge(['share' => $strangerToken]);
            expect(Gate::allows('view', $strangerEntity))->toBeTrue();
        }
    });
});

describe('Share token denials (flow)', function () {
    it('wrong, expired, absent, and missing-entity tokens deny access across entity types', function () {
        foreach (['game', 'campaign'] as $type) {
            // denies access with wrong token
            ['entity' => $wrongTokenEntity] = createShareableEntity($type, [
                'visibility' => 'protected',
                'share_token' => (string) Str::uuid(),
                'share_token_expires_at' => now()->addDays(7),
            ]);
            request()->merge(['share' => (string) Str::uuid()]);
            expect(Gate::allows('view', $wrongTokenEntity))->toBeFalse();

            // denies access with expired token
            $expiredToken = (string) Str::uuid();
            ['entity' => $expiredEntity] = createShareableEntity($type, [
                'visibility' => 'protected',
                'share_token' => $expiredToken,
                'share_token_expires_at' => now()->subDay(),
            ]);
            request()->merge(['share' => $expiredToken]);
            expect(Gate::allows('view', $expiredEntity))->toBeFalse();

            // denies access when no token provided
            ['entity' => $noTokenEntity] = createShareableEntity($type, [
                'visibility' => 'protected',
                'share_token' => (string) Str::uuid(),
                'share_token_expires_at' => now()->addDays(7),
            ]);
            request()->merge(['share' => null]); // null share param: same branch as an absent param (hasValidShareToken guards $token === null)
            expect(Gate::allows('view', $noTokenEntity))->toBeFalse();

            // denies access when entity has no share_token set
            ['entity' => $tokenlessEntity] = createShareableEntity($type, [
                'visibility' => 'protected',
                'share_token' => null,
                'share_token_expires_at' => null,
            ]);
            request()->merge(['share' => (string) Str::uuid()]);
            expect(Gate::allows('view', $tokenlessEntity))->toBeFalse();
        }
    });
});

describe('Share token status guard (flow)', function () {
    it('terminal statuses deny the share-token bypass across entity types', function () {
        foreach (['game', 'campaign'] as $type) {
            // denies access via share token for completed entity
            $completedToken = (string) Str::uuid();
            ['entity' => $completedEntity] = createShareableEntity($type, [
                'visibility' => 'private',
                'share_token' => $completedToken,
                'share_token_expires_at' => now()->addDays(7),
                'status' => 'completed',
            ]);
            request()->merge(['share' => $completedToken]);
            expect(Gate::allows('view', $completedEntity))->toBeFalse();

            // denies access via share token for canceled entity
            $canceledToken = (string) Str::uuid();
            ['entity' => $canceledEntity] = createShareableEntity($type, [
                'visibility' => 'private',
                'share_token' => $canceledToken,
                'share_token_expires_at' => now()->addDays(7),
                'status' => $type === 'game' ? 'canceled' : 'cancelled',
            ]);
            request()->merge(['share' => $canceledToken]);
            expect(Gate::allows('view', $canceledEntity))->toBeFalse();
        }
    });
});
