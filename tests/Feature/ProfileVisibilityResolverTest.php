<?php

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\ProfileVisibilityResolver;

beforeEach(function () {
    $this->resolver = new ProfileVisibilityResolver();
    $this->owner = User::factory()->create();
});

// ═══════════════════════════════════════════════════════════
// SELF-VIEW — SEES EVERYTHING REGARDLESS OF SETTINGS
// ═══════════════════════════════════════════════════════════

describe('Self-viewing', function () {
    it('sees all fields when viewing own profile regardless of privacy settings', function () {
        $this->owner->update([
            'privacy_settings' => array_fill_keys(ProfileVisibilityResolver::FIELDS, 'nobody'),
        ]);

        $visible = $this->resolver->profileFieldsVisible($this->owner, $this->owner);

        expect($visible)->toBe(ProfileVisibilityResolver::FIELDS);
    });

    it('sees all fields with default (null) privacy settings', function () {
        $this->owner->update(['privacy_settings' => null]);

        $visible = $this->resolver->profileFieldsVisible($this->owner, $this->owner);

        expect($visible)->toBe(ProfileVisibilityResolver::FIELDS);
    });
});

// ═══════════════════════════════════════════════════════════
// GUEST VIEW — SEES ONLY "EVERYONE" FIELDS
// ═══════════════════════════════════════════════════════════

describe('Guest viewing', function () {
    it('sees all fields when all set to everyone', function () {
        $this->owner->update([
            'privacy_settings' => array_fill_keys(ProfileVisibilityResolver::FIELDS, 'everyone'),
        ]);

        $visible = $this->resolver->profileFieldsVisible(null, $this->owner);

        expect($visible)->toBe(ProfileVisibilityResolver::FIELDS);
    });

    it('sees no fields when all set to friends', function () {
        $this->owner->update([
            'privacy_settings' => array_fill_keys(ProfileVisibilityResolver::FIELDS, 'friends'),
        ]);

        $visible = $this->resolver->profileFieldsVisible(null, $this->owner);

        expect($visible)->toBe([]);
    });

    it('sees no fields when all set to nobody', function () {
        $this->owner->update([
            'privacy_settings' => array_fill_keys(ProfileVisibilityResolver::FIELDS, 'nobody'),
        ]);

        $visible = $this->resolver->profileFieldsVisible(null, $this->owner);

        expect($visible)->toBe([]);
    });

    it('sees only everyone fields with mixed settings', function () {
        $this->owner->update([
            'privacy_settings' => [
                'location' => 'everyone',
                'game_systems' => 'friends',
                'vibes' => 'everyone',
                'campaigns' => 'nobody',
                'teams' => 'friends',
                'friends_list' => 'nobody',
            ],
        ]);

        $visible = $this->resolver->profileFieldsVisible(null, $this->owner);

        expect($visible)->toBe(['location', 'vibes']);
    });

    it('sees all fields with default (null) privacy settings since default is everyone', function () {
        $this->owner->update(['privacy_settings' => null]);

        $visible = $this->resolver->profileFieldsVisible(null, $this->owner);

        expect($visible)->toBe(ProfileVisibilityResolver::FIELDS);
    });

    it('sees partial fields when only some have explicit settings', function () {
        $this->owner->update([
            'privacy_settings' => [
                'location' => 'nobody',
                'friends_list' => 'friends',
            ],
        ]);

        $visible = $this->resolver->profileFieldsVisible(null, $this->owner);

        // Unset fields default to 'everyone'
        expect($visible)->toContain('game_systems', 'vibes', 'campaigns', 'teams');
        expect($visible)->not->toContain('location', 'friends_list');
    });
});

// ═══════════════════════════════════════════════════════════
// STRANGER VIEW — SAME AS GUEST FOR VISIBILITY PURPOSES
// ═══════════════════════════════════════════════════════════

describe('Stranger viewing', function () {
    it('sees only everyone fields, not friends fields', function () {
        $stranger = User::factory()->create();
        $this->owner->update([
            'privacy_settings' => [
                'location' => 'everyone',
                'game_systems' => 'friends',
                'vibes' => 'everyone',
                'campaigns' => 'nobody',
                'teams' => 'friends',
                'friends_list' => 'everyone',
            ],
        ]);

        $visible = $this->resolver->profileFieldsVisible($stranger, $this->owner);

        expect($visible)->toBe(['location', 'vibes', 'friends_list']);
    });
});

// ═══════════════════════════════════════════════════════════
// FRIEND/TEAMMATE VIEW — SEES EVERYONE + FRIENDS FIELDS
// ═══════════════════════════════════════════════════════════

describe('Friend viewing', function () {
    it('sees everyone + friends fields but not nobody fields', function () {
        $friend = User::factory()->create();
        // Mutual follow = friend
        UserRelationship::follow($this->owner, $friend);
        UserRelationship::follow($friend, $this->owner);

        $this->owner->update([
            'privacy_settings' => [
                'location' => 'everyone',
                'game_systems' => 'friends',
                'vibes' => 'nobody',
                'campaigns' => 'friends',
                'teams' => 'everyone',
                'friends_list' => 'nobody',
            ],
        ]);

        $visible = $this->resolver->profileFieldsVisible($friend, $this->owner);

        expect($visible)->toBe(['location', 'game_systems', 'campaigns', 'teams']);
    });
});

describe('Teammate viewing', function () {
    it('sees everyone + friends fields through shared team membership', function () {
        $teammate = User::factory()->create();
        $team = Team::factory()->create();

        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $this->owner->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $teammate->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->owner->update([
            'privacy_settings' => [
                'location' => 'friends',
                'game_systems' => 'everyone',
                'vibes' => 'nobody',
                'campaigns' => 'friends',
                'teams' => 'everyone',
                'friends_list' => 'nobody',
            ],
        ]);

        $visible = $this->resolver->profileFieldsVisible($teammate, $this->owner);

        expect($visible)->toBe(['location', 'game_systems', 'campaigns', 'teams']);
    });
});

// ═══════════════════════════════════════════════════════════
// BLOCKED VIEWERS — SEE NO PROFILE FIELDS
// ═══════════════════════════════════════════════════════════

describe('Blocked viewer', function () {
    it('sees no fields when blocked by owner', function () {
        $blocked = User::factory()->create();
        UserRelationship::block($this->owner, $blocked);

        $this->owner->update([
            'privacy_settings' => array_fill_keys(ProfileVisibilityResolver::FIELDS, 'everyone'),
        ]);

        $visible = $this->resolver->profileFieldsVisible($blocked, $this->owner);

        expect($visible)->toBe([]);
    });

    it('sees no fields when viewer has blocked the owner', function () {
        $blocker = User::factory()->create();
        UserRelationship::block($blocker, $this->owner);

        $this->owner->update([
            'privacy_settings' => array_fill_keys(ProfileVisibilityResolver::FIELDS, 'everyone'),
        ]);

        $visible = $this->resolver->profileFieldsVisible($blocker, $this->owner);

        expect($visible)->toBe([]);
    });
});

// ═══════════════════════════════════════════════════════════
// FIELD-LEVEL GRANULARITY
// ═══════════════════════════════════════════════════════════

describe('Field-level granularity', function () {
    it('each field is independently controlled', function () {
        $viewer = User::factory()->create();

        $this->owner->update([
            'privacy_settings' => [
                'location' => 'everyone',
                'game_systems' => 'friends',
                'vibes' => 'nobody',
                'campaigns' => 'everyone',
                'teams' => 'nobody',
                'friends_list' => 'friends',
            ],
        ]);

        $visible = $this->resolver->profileFieldsVisible($viewer, $this->owner);

        // Stranger sees only 'everyone' fields
        expect($visible)->toBe(['location', 'campaigns']);
    });

    it('unknown privacy setting values default to visible', function () {
        $viewer = User::factory()->create();

        $this->owner->update([
            'privacy_settings' => [
                'location' => 'invalid_value',
            ],
        ]);

        $visible = $this->resolver->profileFieldsVisible($viewer, $this->owner);

        expect($visible)->toContain('location');
    });
});

// ═══════════════════════════════════════════════════════════
// FIELDS CONSTANT
// ═══════════════════════════════════════════════════════════

describe('FIELDS constant', function () {
    it('contains all expected profile fields', function () {
        expect(ProfileVisibilityResolver::FIELDS)->toBe([
            'location',
            'game_systems',
            'vibes',
            'campaigns',
            'teams',
            'friends_list',
        ]);
    });
});
