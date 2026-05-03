<?php

use App\Enums\RelationshipType;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Models\UserRelationship;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;


// ── Helpers ─────────────────────────────────────────

function inviteTestMakeFriend(User $a, User $b): void
{
    UserRelationship::create([
        'user_id' => $a->id,
        'related_user_id' => $b->id,
        'type' => RelationshipType::Follow,
    ]);
    UserRelationship::create([
        'user_id' => $b->id,
        'related_user_id' => $a->id,
        'type' => RelationshipType::Follow,
    ]);
}

function inviteTestBlock(User $blocker, User $blocked): void
{
    UserRelationship::create([
        'user_id' => $blocker->id,
        'related_user_id' => $blocked->id,
        'type' => RelationshipType::Block,
    ]);
}

function inviteTestCreateGameWithOwner(array $attrs = []): array
{
    $owner = User::factory()->create(['profile_complete' => true]);
    $game = Game::factory()->create(['owner_id' => $owner->id, ...$attrs]);
    return ['owner' => $owner, 'game' => $game];
}

function inviteTestCreateCampaignWithOwner(array $attrs = []): array
{
    $owner = User::factory()->create(['profile_complete' => true]);
    $campaign = Campaign::factory()->create(['owner_id' => $owner->id, ...$attrs]);
    return ['owner' => $owner, 'campaign' => $campaign];
}

// ═══════════════════════════════════════════════════════════
// GAME INVITATION CREATION
// ═══════════════════════════════════════════════════════════

describe('Invitation — Game Creation', function () {
    test('invitation creates pending participant', function () {
        ['owner' => $owner, 'game' => $game] = inviteTestCreateGameWithOwner();
        $friend = User::factory()->create(['profile_complete' => true]);
        inviteTestMakeFriend($owner, $friend);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants')
            ->assertHasNoErrors();

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $friend->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// FRIEND VALIDATION
// ═══════════════════════════════════════════════════════════

describe('Invitation — Friend Validation', function () {
    test('cannot invite non-friend to game', function () {
        ['owner' => $owner, 'game' => $game] = inviteTestCreateGameWithOwner();
        $stranger = User::factory()->create(['profile_complete' => true]);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$stranger->id])
            ->call('inviteParticipants');

        $this->assertDatabaseMissing('game_participants', [
            'game_id' => $game->id,
            'user_id' => $stranger->id,
            'role' => 'invited',
        ]);
    })->group('smoke');

    test('cannot invite self', function () {
        ['owner' => $owner, 'game' => $game] = inviteTestCreateGameWithOwner();

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$owner->id])
            ->call('inviteParticipants');

        $this->assertDatabaseMissing('game_participants', [
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => 'invited',
        ]);
    });

    test('skips nonexistent user ID gracefully', function () {
        ['owner' => $owner, 'game' => $game] = inviteTestCreateGameWithOwner();
        $fakeUuid = \Illuminate\Support\Str::uuid()->toString();

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$fakeUuid])
            ->call('inviteParticipants');

        $this->assertDatabaseMissing('game_participants', [
            'game_id' => $game->id,
            'user_id' => $fakeUuid,
        ]);
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// DUPLICATE PREVENTION
// ═══════════════════════════════════════════════════════════

describe('Invitation — Duplicate Prevention', function () {
    test('game duplicate invite skipped', function () {
        ['owner' => $owner, 'game' => $game] = inviteTestCreateGameWithOwner();
        $friend = User::factory()->create(['profile_complete' => true]);
        inviteTestMakeFriend($owner, $friend);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $friend->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants');

        expect(GameParticipant::where('game_id', $game->id)
            ->where('user_id', $friend->id)
            ->count())->toBe(1);
    });

    test('cannot re-invite approved player', function () {
        ['owner' => $owner, 'game' => $game] = inviteTestCreateGameWithOwner();
        $friend = User::factory()->create(['profile_complete' => true]);
        inviteTestMakeFriend($owner, $friend);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $friend->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants');

        expect(GameParticipant::where('game_id', $game->id)
            ->where('user_id', $friend->id)
            ->count())->toBe(1);
    });

    test('cannot re-invite rejected player', function () {
        ['owner' => $owner, 'game' => $game] = inviteTestCreateGameWithOwner();
        $friend = User::factory()->create(['profile_complete' => true]);
        inviteTestMakeFriend($owner, $friend);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $friend->id,
            'role' => 'player',
            'status' => 'rejected',
        ]);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants');

        expect(GameParticipant::where('game_id', $game->id)
            ->where('user_id', $friend->id)
            ->count())->toBe(1);
    });
});

// ═══════════════════════════════════════════════════════════
// BLOCKED USER CANNOT BE INVITED
// ═══════════════════════════════════════════════════════════

describe('Invitation — Blocked Users', function () {
    test('blocked user cannot be invited to game', function () {
        ['owner' => $owner, 'game' => $game] = inviteTestCreateGameWithOwner();
        $friend = User::factory()->create(['profile_complete' => true]);
        inviteTestMakeFriend($owner, $friend);

        inviteTestBlock($owner, $friend);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants');

        $this->assertDatabaseMissing('game_participants', [
            'game_id' => $game->id,
            'user_id' => $friend->id,
            'role' => 'invited',
        ]);
    })->group('smoke');

    test('user who blocked owner cannot be invited', function () {
        ['owner' => $owner, 'game' => $game] = inviteTestCreateGameWithOwner();
        $friend = User::factory()->create(['profile_complete' => true]);
        inviteTestMakeFriend($owner, $friend);

        inviteTestBlock($friend, $owner);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id])
            ->call('inviteParticipants');

        $this->assertDatabaseMissing('game_participants', [
            'game_id' => $game->id,
            'user_id' => $friend->id,
            'role' => 'invited',
        ]);
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// BATCH INVITATION WITH MIXED VALIDITY
// ═══════════════════════════════════════════════════════════

describe('Invitation — Batch', function () {
    test('batch invite skips invalid but creates valid', function () {
        ['owner' => $owner, 'game' => $game] = inviteTestCreateGameWithOwner();
        $friend = User::factory()->create(['profile_complete' => true]);
        $stranger = User::factory()->create(['profile_complete' => true]);
        inviteTestMakeFriend($owner, $friend);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [$friend->id, $stranger->id])
            ->call('inviteParticipants')
            ->assertHasNoErrors();

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'user_id' => $friend->id,
            'role' => 'invited',
        ]);
        $this->assertDatabaseMissing('game_participants', [
            'game_id' => $game->id,
            'user_id' => $stranger->id,
        ]);
    })->group('smoke');

    test('empty selection shows error', function () {
        ['owner' => $owner, 'game' => $game] = inviteTestCreateGameWithOwner();

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->set('selectedFriendIds', [])
            ->call('inviteParticipants')
            ->assertHasErrors(['selectedFriendIds']);
    });
});

// ═══════════════════════════════════════════════════════════
// FRIENDS-SELECTED EVENT SYNC
// ═══════════════════════════════════════════════════════════

describe('Invitation — Event Sync', function () {
    test('friends-selected event syncs to manage participants', function () {
        ['owner' => $owner, 'game' => $game] = inviteTestCreateGameWithOwner();
        $friend = User::factory()->create(['profile_complete' => true]);
        inviteTestMakeFriend($owner, $friend);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->dispatch('friends-selected', ids: [$friend->id])
            ->assertSet('selectedFriendIds', [$friend->id]);
    });
});
