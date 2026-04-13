<?php

use App\Models\Campaign;
use App\Models\User;
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
