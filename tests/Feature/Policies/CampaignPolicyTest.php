<?php

use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedPermissions();
    seedRoles();

    $this->admin = User::factory()->create();
    $this->owner = User::factory()->create();
    $this->regularUser = User::factory()->create();

    setPermissionsTeamId(1);
    $this->admin->assignRole('Platform Admin');
    $this->admin->unsetRelations();
    setPermissionsTeamId(1);

    $this->publicCampaign = Campaign::factory()->create([
        'owner_id' => $this->owner->id,
        'visibility' => 'public',
    ]);

    $this->privateCampaign = Campaign::factory()->create([
        'owner_id' => $this->owner->id,
        'visibility' => 'private',
    ]);

    $this->protectedCampaign = Campaign::factory()->create([
        'owner_id' => $this->owner->id,
        'visibility' => 'protected',
    ]);
});

// ── view ─────────────────────────────────────────────

test('guest can view public campaign', function () {
    expect(Gate::allows('view', $this->publicCampaign))->toBeTrue();
});

test('guest cannot view private campaign', function () {
    expect(Gate::allows('view', $this->privateCampaign))->toBeFalse();
});

test('owner can view their private campaign', function () {
    $this->actingAs($this->owner);
    expect(Gate::allows('view', $this->privateCampaign))->toBeTrue();
});

// ── protected visibility ─────────────────────────────

test('guest cannot view protected campaign', function () {
    expect(Gate::allows('view', $this->protectedCampaign))->toBeFalse();
});

test('owner can view their protected campaign', function () {
    $this->actingAs($this->owner);
    expect(Gate::allows('view', $this->protectedCampaign))->toBeTrue();
});

test('stranger cannot view protected campaign', function () {
    $this->actingAs($this->regularUser);
    expect(Gate::allows('view', $this->protectedCampaign))->toBeFalse();
});

test('friend of owner can view protected campaign', function () {
    UserRelationship::follow($this->regularUser, $this->owner);
    UserRelationship::follow($this->owner, $this->regularUser);

    $this->actingAs($this->regularUser);
    expect(Gate::allows('view', $this->protectedCampaign))->toBeTrue();
});

test('participant can view protected campaign', function () {
    CampaignParticipant::create([
        'campaign_id' => $this->protectedCampaign->id,
        'user_id' => $this->regularUser->id,
        'role' => 'player',
        'status' => 'approved',
    ]);

    $this->actingAs($this->regularUser);
    expect(Gate::allows('view', $this->protectedCampaign))->toBeTrue();
});

// ── update ───────────────────────────────────────────

test('owner can update their campaign', function () {
    $this->actingAs($this->owner);
    expect(Gate::allows('update', $this->publicCampaign))->toBeTrue();
});

test('Platform Admin can update any campaign', function () {
    $this->actingAs($this->admin);
    expect(Gate::allows('update', $this->publicCampaign))->toBeTrue();
});

test('regular user cannot update campaign', function () {
    $this->actingAs($this->regularUser);
    expect(Gate::allows('update', $this->publicCampaign))->toBeFalse();
});

// ── delete ───────────────────────────────────────────

test('owner can delete their campaign', function () {
    $this->actingAs($this->owner);
    expect(Gate::allows('delete', $this->publicCampaign))->toBeTrue();
});

test('regular user cannot delete campaign', function () {
    $this->actingAs($this->regularUser);
    expect(Gate::allows('delete', $this->publicCampaign))->toBeFalse();
});
