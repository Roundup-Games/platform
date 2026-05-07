<?php

use App\Models\Campaign;
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
// PROTECTED CAMPAIGN + VALID SHARE TOKEN
// ═══════════════════════════════════════════════════════════

describe('Protected campaign with share token', function () {
    it('grants guest access with valid share token', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $campaign))->toBeTrue();
    });

    it('grants stranger access with valid share token', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
        ]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger);
        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $campaign))->toBeTrue();
    });

    it('denies access with wrong token', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
        ]);

        request()->merge(['share' => (string) Str::uuid()]);

        expect(Gate::allows('view', $campaign))->toBeFalse();
    });

    it('denies access with expired token', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
            'share_token' => $token,
            'share_token_expires_at' => now()->subDay(),
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $campaign))->toBeFalse();
    });

    it('denies access when no token provided', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
        ]);

        // No share param in request
        expect(Gate::allows('view', $campaign))->toBeFalse();
    });

    it('denies access when campaign has no share_token set', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
            'share_token' => null,
            'share_token_expires_at' => null,
        ]);

        request()->merge(['share' => (string) Str::uuid()]);

        expect(Gate::allows('view', $campaign))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// PRIVATE CAMPAIGN + VALID SHARE TOKEN
// ═══════════════════════════════════════════════════════════

describe('Private campaign with share token', function () {
    it('grants guest access with valid share token', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $campaign))->toBeTrue();
    });

    it('denies access with expired token on private campaign', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
            'share_token' => $token,
            'share_token_expires_at' => now()->subHour(),
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $campaign))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// TOKEN WITHOUT EXPIRY (never expires)
// ═══════════════════════════════════════════════════════════

describe('Share token without expiry', function () {
    it('grants access when token has no expiry date', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'protected',
            'share_token' => $token,
            'share_token_expires_at' => null,
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $campaign))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// LOGGING (policy is side-effect-free — no logging)
// ═══════════════════════════════════════════════════════════

describe('Share token logging', function () {
    it('does not log when share token grants access', function () {
        Log::spy();

        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
        ]);

        request()->merge(['share' => $token]);

        Gate::allows('view', $campaign);

        Log::shouldNotHaveReceived('info');
    });
});

// ═══════════════════════════════════════════════════════════
// STATUS CHECK — completed/cancelled campaigns deny share token bypass
// ═══════════════════════════════════════════════════════════

describe('Share token status guard', function () {
    it('denies access via share token for completed campaign', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'status' => 'completed',
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $campaign))->toBeFalse();
    });

    it('denies access via share token for cancelled campaign', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'status' => 'cancelled',
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $campaign))->toBeFalse();
    });

    it('grants access via share token for active campaign', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'status' => 'active',
        ]);

        request()->merge(['share' => $token]);

        expect(Gate::allows('view', $campaign))->toBeTrue();
    });
});
