<?php

use App\Models\GMProfile;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    seedPermissions();
    seedRoles();
    setPermissionsTeamId(1);

    $this->gmUser = User::factory()->create();
    $this->gmProfile = GMProfile::factory()->create(['user_id' => $this->gmUser->id]);
    $this->otherUser = User::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Platform Admin');
    $this->admin->unsetRelations();

    setPermissionsTeamId(1);
});

describe('GMProfilePolicy', function () {
    describe('before() — global admin bypass', function () {
        test('Platform Admin can do anything on GM profiles', function () {
            $this->actingAs($this->admin);
            expect(Gate::allows('view', $this->gmProfile))->toBeTrue();
            expect(Gate::allows('update', $this->gmProfile))->toBeTrue();
        });
    });

    describe('view', function () {
        test('guest can view a GM profile', function () {
            expect(Gate::allows('view', $this->gmProfile))->toBeTrue();
        });

        test('any authenticated user can view a GM profile', function () {
            $this->actingAs($this->otherUser);
            expect(Gate::allows('view', $this->gmProfile))->toBeTrue();
        });
    });

    describe('update', function () {
        test('GM owner can update their own profile', function () {
            $this->actingAs($this->gmUser);
            expect(Gate::allows('update', $this->gmProfile))->toBeTrue();
        });

        test('other user cannot update someone else GM profile', function () {
            $this->actingAs($this->otherUser);
            expect(Gate::allows('update', $this->gmProfile))->toBeFalse();
        });
    });
});
