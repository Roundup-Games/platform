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

    it('stranger sees only "everyone" fields — same as guest', function () {
        $stranger = User::factory()->create();

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

        $visible = $this->resolver->profileFieldsVisible($stranger, $this->owner);

        expect($visible)->toBe(['location', 'campaigns']);
    });

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
    });

    // smoke: self always sees all fields regardless of privacy settings
    it('self sees all fields regardless of settings', function () {
        $this->owner->update([
            'privacy_settings' => array_fill_keys(ProfileVisibilityResolver::FIELDS, 'nobody'),
        ]);

        $visible = $this->resolver->profileFieldsVisible($this->owner, $this->owner);

        expect($visible)->toBe(ProfileVisibilityResolver::FIELDS);
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// PER-SETTING MATRIX: EACH FIELD INDEPENDENTLY
// ═══════════════════════════════════════════════════════════

describe('Each field is independently controlled per setting', function () {
    foreach (ProfileVisibilityResolver::FIELDS as $field) {
        test("{$field} with 'everyone' is visible to guest", function () use ($field) {
            $this->owner->update([
                'privacy_settings' => [$field => 'everyone'],
            ]);

            $visible = $this->resolver->profileFieldsVisible(null, $this->owner);

            expect($visible)->toContain($field);
        });

        test("{$field} with 'friends' is hidden from guest", function () use ($field) {
            $this->owner->update([
                'privacy_settings' => [$field => 'friends'],
            ]);

            $visible = $this->resolver->profileFieldsVisible(null, $this->owner);

            expect($visible)->not->toContain($field);
        });

        test("{$field} with 'friends' is visible to friend", function () use ($field) {
            $friend = User::factory()->create();
            UserRelationship::follow($this->owner, $friend);
            UserRelationship::follow($friend, $this->owner);

            $this->owner->update([
                'privacy_settings' => [$field => 'friends'],
            ]);

            $visible = $this->resolver->profileFieldsVisible($friend, $this->owner);

            expect($visible)->toContain($field);
        });

        test("{$field} with 'friends' is visible to teammate", function () use ($field) {
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
                'privacy_settings' => [$field => 'friends'],
            ]);

            $visible = $this->resolver->profileFieldsVisible($teammate, $this->owner);

            expect($visible)->toContain($field);
        });

        test("{$field} with 'friends' is hidden from stranger", function () use ($field) {
            $stranger = User::factory()->create();

            $this->owner->update([
                'privacy_settings' => [$field => 'friends'],
            ]);

            $visible = $this->resolver->profileFieldsVisible($stranger, $this->owner);

            expect($visible)->not->toContain($field);
        });

        test("{$field} with 'nobody' is hidden from everyone except self", function () use ($field) {
            $this->owner->update([
                'privacy_settings' => [$field => 'nobody'],
            ]);

            // Guest
            expect($this->resolver->profileFieldsVisible(null, $this->owner))
                ->not->toContain($field);

            // Stranger
            $stranger = User::factory()->create();
            expect($this->resolver->profileFieldsVisible($stranger, $this->owner))
                ->not->toContain($field);

            // Friend
            $friend = User::factory()->create();
            UserRelationship::follow($this->owner, $friend);
            UserRelationship::follow($friend, $this->owner);
            expect($this->resolver->profileFieldsVisible($friend, $this->owner))
                ->not->toContain($field);

            // Self — sees everything
            expect($this->resolver->profileFieldsVisible($this->owner, $this->owner))
                ->toContain($field);
        });
    }
});

// ═══════════════════════════════════════════════════════════
// DEFAULT (NULL) SETTINGS — ALL FIELDS VISIBLE TO EVERYONE
// ═══════════════════════════════════════════════════════════

describe('Default privacy settings (null)', function () {
    it('guest sees all fields when privacy_settings is null', function () {
        $this->owner->update(['privacy_settings' => null]);

        $visible = $this->resolver->profileFieldsVisible(null, $this->owner);

        expect($visible)->toBe(ProfileVisibilityResolver::FIELDS);
    });

    it('stranger sees all fields when privacy_settings is null', function () {
        $stranger = User::factory()->create();
        $this->owner->update(['privacy_settings' => null]);

        $visible = $this->resolver->profileFieldsVisible($stranger, $this->owner);

        expect($visible)->toBe(ProfileVisibilityResolver::FIELDS);
    });

    it('friend sees all fields when privacy_settings is null', function () {
        $friend = User::factory()->create();
        UserRelationship::follow($this->owner, $friend);
        UserRelationship::follow($friend, $this->owner);
        $this->owner->update(['privacy_settings' => null]);

        $visible = $this->resolver->profileFieldsVisible($friend, $this->owner);

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

    it('self still sees all fields even after being blocked by another user', function () {
        // Owner blocks someone else — owner viewing own profile should be unaffected
        $other = User::factory()->create();
        UserRelationship::block($this->owner, $other);

        $this->owner->update([
            'privacy_settings' => array_fill_keys(ProfileVisibilityResolver::FIELDS, 'nobody'),
        ]);

        $visible = $this->resolver->profileFieldsVisible($this->owner, $this->owner);

        expect($visible)->toBe(ProfileVisibilityResolver::FIELDS);
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

    it('friend who is also a teammate gets same access as just friend', function () {
        $friendTeammate = User::factory()->create();
        // Make them a friend
        UserRelationship::follow($this->owner, $friendTeammate);
        UserRelationship::follow($friendTeammate, $this->owner);
        // Also a teammate
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
            'user_id' => $friendTeammate->id,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->owner->update([
            'privacy_settings' => [
                'location' => 'everyone',
                'game_systems' => 'friends',
                'vibes' => 'nobody',
                'campaigns' => 'friends',
                'teams' => 'everyone',
                'friends_list' => 'nobody',
                'stats' => 'nobody',
            ],
        ]);

        $visible = $this->resolver->profileFieldsVisible($friendTeammate, $this->owner);

        // Same as friend: everyone + friends fields
        expect($visible)->toBe(['location', 'game_systems', 'campaigns', 'teams']);
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
