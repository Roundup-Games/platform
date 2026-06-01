<?php

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\ProfileVisibilityResolver;
use App\Enums\ParticipantRole;

beforeEach(function () {
    $this->resolver = new ProfileVisibilityResolver();
    $this->owner = User::factory()->create();
});

// ═══════════════════════════════════════════════════════════
// FULL MATRIX: ALL FIELD SETTINGS × ALL RELATIONSHIP LEVELS
// ═══════════════════════════════════════════════════════════

describe('Visibility matrix: every field × every relationship level', function () {
    // smoke: basic visibility matrix — guests see only 'everyone' fields
    it('guest sees only "everyone" fields when all three settings are used', function () {
        $this->owner->update([
            'privacy_settings' => [
                'location' => 'everyone',
                'game_systems' => 'friends',
                'vibes' => 'nobody',
                'campaigns' => 'everyone',
                'teams' => 'friends',
                'friends_list' => 'nobody',
                'stats' => 'nobody',
            ],
        ]);

        $visible = $this->resolver->profileFieldsVisible(null, $this->owner);

        expect($visible)->toBe(['location', 'campaigns']);
    })->group('smoke');

    it('friend sees "everyone" + "friends" fields', function () {
        $friend = User::factory()->create();
        UserRelationship::follow($this->owner, $friend);
        UserRelationship::follow($friend, $this->owner);

        $this->owner->update([
            'privacy_settings' => [
                'location' => 'everyone',
                'game_systems' => 'friends',
                'vibes' => 'nobody',
                'campaigns' => 'everyone',
                'teams' => 'friends',
                'friends_list' => 'nobody',
                'stats' => 'nobody',
            ],
        ]);

        $visible = $this->resolver->profileFieldsVisible($friend, $this->owner);

        expect($visible)->toBe(['location', 'game_systems', 'campaigns', 'teams']);
    });

    it('teammate sees "everyone" + "friends" fields (same as friend)', function () {
        $teammate = User::factory()->create();
        $team = Team::factory()->create();
        \App\Models\TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $this->owner->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        \App\Models\TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $teammate->id,
            'role' => ParticipantRole::Player->value,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->owner->update([
            'privacy_settings' => [
                'location' => 'everyone',
                'game_systems' => 'friends',
                'vibes' => 'nobody',
                'campaigns' => 'everyone',
                'teams' => 'friends',
                'friends_list' => 'nobody',
                'stats' => 'nobody',
            ],
        ]);

        $visible = $this->resolver->profileFieldsVisible($teammate, $this->owner);

        expect($visible)->toBe(['location', 'game_systems', 'campaigns', 'teams']);
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// PER-SETTING MATRIX: PROOF WITH 2 FIELDS (representative)
// ═══════════════════════════════════════════════════════════

describe('Each field is independently controlled per setting (representative)', function () {
    test("'friends' setting: visible to friend, hidden from guest", function () {
        $friend = User::factory()->create();
        UserRelationship::follow($this->owner, $friend);
        UserRelationship::follow($friend, $this->owner);

        $this->owner->update([
            'privacy_settings' => ['location' => 'friends'],
        ]);

        expect($this->resolver->profileFieldsVisible(null, $this->owner))
            ->not->toContain('location');
        expect($this->resolver->profileFieldsVisible($friend, $this->owner))
            ->toContain('location');
    });

    test("'nobody' setting: hidden from all except self", function () {
        $this->owner->update([
            'privacy_settings' => ['game_systems' => 'nobody'],
        ]);

        expect($this->resolver->profileFieldsVisible(null, $this->owner))
            ->not->toContain('game_systems');
        expect($this->resolver->profileFieldsVisible($this->owner, $this->owner))
            ->toContain('game_systems');
    });
});

// ═══════════════════════════════════════════════════════════
// DEFAULT (NULL) SETTINGS — ALL FIELDS VISIBLE TO EVERYONE
// ═══════════════════════════════════════════════════════════

describe('Default privacy settings (null or empty)', function () {
    it('guest sees all fields when privacy_settings is null', function () {
        $this->owner->update(['privacy_settings' => null]);

        $visible = $this->resolver->profileFieldsVisible(null, $this->owner);

        expect($visible)->toBe(ProfileVisibilityResolver::FIELDS);
    });

    it('empty privacy_settings behaves same as null', function () {
        $this->owner->update(['privacy_settings' => []]);

        $visible = $this->resolver->profileFieldsVisible(null, $this->owner);

        expect($visible)->toBe(ProfileVisibilityResolver::FIELDS);
    });
});

// ═══════════════════════════════════════════════════════════
// BLOCKED VIEWERS
// ═══════════════════════════════════════════════════════════

describe('Blocked viewers', function () {
    it('blocked viewer sees no fields even if all are "everyone"', function () {
        $blocked = User::factory()->create();
        UserRelationship::block($this->owner, $blocked);

        $this->owner->update([
            'privacy_settings' => array_fill_keys(ProfileVisibilityResolver::FIELDS, 'everyone'),
        ]);

        $visible = $this->resolver->profileFieldsVisible($blocked, $this->owner);

        expect($visible)->toBe([]);
    });

    it('viewer who blocked owner sees no fields', function () {
        $blocker = User::factory()->create();
        UserRelationship::block($blocker, $this->owner);

        $this->owner->update([
            'privacy_settings' => array_fill_keys(ProfileVisibilityResolver::FIELDS, 'everyone'),
        ]);

        $visible = $this->resolver->profileFieldsVisible($blocker, $this->owner);

        expect($visible)->toBe([]);
    });

    it('blocked friend loses all access', function () {
        $friend = User::factory()->create();
        UserRelationship::follow($this->owner, $friend);
        UserRelationship::follow($friend, $this->owner);

        // Now block them
        UserRelationship::block($this->owner, $friend);

        $this->owner->update([
            'privacy_settings' => array_fill_keys(ProfileVisibilityResolver::FIELDS, 'everyone'),
        ]);

        $visible = $this->resolver->profileFieldsVisible($friend, $this->owner);

        expect($visible)->toBe([]);
    });

});

// ═══════════════════════════════════════════════════════════
// EDGE CASES
// ═══════════════════════════════════════════════════════════

describe('Edge cases', function () {
    it('unknown privacy setting values default to visible', function () {
        $viewer = User::factory()->create();

        $this->owner->update([
            'privacy_settings' => [
                'location' => 'invalid_value',
                'game_systems' => 'also_unknown',
            ],
        ]);

        $visible = $this->resolver->profileFieldsVisible($viewer, $this->owner);

        expect($visible)->toContain('location', 'game_systems');
    });

    it('partial privacy settings only affect explicitly set fields', function () {
        $viewer = User::factory()->create();

        $this->owner->update([
            'privacy_settings' => [
                'location' => 'nobody',
                'friends_list' => 'friends',
            ],
        ]);

        $visible = $this->resolver->profileFieldsVisible($viewer, $this->owner);

        // Unset fields default to 'everyone'
        expect($visible)->toContain('game_systems', 'vibes', 'campaigns', 'teams');
        expect($visible)->not->toContain('location', 'friends_list');
    });

    it('one-way follow (not mutual) does not grant friends access', function () {
        $follower = User::factory()->create();
        // Follower follows the owner, but owner does NOT follow back
        UserRelationship::follow($follower, $this->owner);

        $this->owner->update([
            'privacy_settings' => [
                'location' => 'friends',
                'game_systems' => 'everyone',
                'vibes' => 'friends',
                'campaigns' => 'everyone',
                'teams' => 'nobody',
                'friends_list' => 'nobody',
                'stats' => 'nobody',
            ],
        ]);

        $visible = $this->resolver->profileFieldsVisible($follower, $this->owner);

        // Not a mutual friend — only sees 'everyone' fields, not 'friends' fields
        expect($visible)->toBe(['game_systems', 'campaigns']);
        expect($visible)->not->toContain('location');
        expect($visible)->not->toContain('vibes');
    });
});
