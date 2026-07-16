<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Profile\AuthenticatedProfile;
use App\Livewire\Profile\PublicProfile;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Models\UserRelationship;
use Livewire\Livewire;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

/**
 * Regression coverage for the profile-page third-party visibility leak.
 *
 * resolveVisibleGames() / resolveVisibleCampaigns() originally filtered BOTH
 * owned and participated content by the viewer→profile-user relationship
 * ($scope). That is correct for content the profile user OWNS, but for content
 * owned by a THIRD PARTY that the profile user merely plays in, the owner's own
 * visibility intent was ignored: a protected game/campaign owned by someone
 * outside the viewer's circle leaked onto a friend's profile — and the detail
 * page would 403 the same viewer, so the listing was inconsistent with the
 * policy. Participated (third-party) content is now gated by visibleTo($viewer).
 */
describe('Profile third-party content visibility', function () {
    it('hides a protected third-party game from a friend of the participant (PublicProfile)', function () {
        [$viewer, $profileUser, $otherOwner, $game] = setupParticipatedGame('protected');

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->doesntContain('id', $game->id));
    });

    it('hides a protected third-party game from a friend of the participant (AuthenticatedProfile)', function () {
        [$viewer, $profileUser, $otherOwner, $game] = setupParticipatedGame('protected');

        Livewire::actingAs($viewer)
            ->test(AuthenticatedProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->doesntContain('id', $game->id));
    });

    it('hides a private third-party game from a friend of the participant', function () {
        [$viewer, $profileUser, $otherOwner, $game] = setupParticipatedGame('private');

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->doesntContain('id', $game->id));
    });

    it('still shows a public third-party game the profile user plays in (no regression)', function () {
        [$viewer, $profileUser, $otherOwner, $game] = setupParticipatedGame('public');

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->contains('id', $game->id));
    });

    it('shows a protected third-party game when the viewer is also connected to its owner', function () {
        // Viewer is a mutual follow of BOTH the profile user and the game owner,
        // so visibleTo($viewer) admits the owner's protected game.
        [$viewer, $profileUser, $otherOwner, $game] = setupParticipatedGame('protected');
        UserRelationship::follow($viewer, $otherOwner);
        UserRelationship::follow($otherOwner, $viewer);

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('games', fn ($games) => $games->contains('id', $game->id));
    });

    it('hides a protected third-party campaign from a friend of the participant (PublicProfile)', function () {
        [$viewer, $profileUser, $otherOwner, $campaign] = setupParticipatedCampaign('protected');

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('campaigns', fn ($campaigns) => $campaigns->doesntContain('id', $campaign->id));
    });

    it('hides a protected third-party campaign from a friend of the participant (AuthenticatedProfile)', function () {
        [$viewer, $profileUser, $otherOwner, $campaign] = setupParticipatedCampaign('protected');

        Livewire::actingAs($viewer)
            ->test(AuthenticatedProfile::class, ['user' => $profileUser])
            ->assertViewHas('campaigns', fn ($campaigns) => $campaigns->doesntContain('id', $campaign->id));
    });

    it('still shows a public third-party campaign the profile user plays in (no regression)', function () {
        [$viewer, $profileUser, $otherOwner, $campaign] = setupParticipatedCampaign('public');

        Livewire::actingAs($viewer)
            ->test(PublicProfile::class, ['user' => $profileUser])
            ->assertViewHas('campaigns', fn ($campaigns) => $campaigns->contains('id', $campaign->id));
    });
});

/**
 * Build the leak scenario: viewer is a mutual friend of $profileUser, who plays
 * (approved) in a game owned by $otherOwner. The viewer has NO relationship with
 * $otherOwner. Returns [$viewer, $profileUser, $otherOwner, $game].
 *
 * @param  'public'|'protected'|'private'  $visibility
 * @return array{0: User, 1: User, 2: User, 3: Game}
 */
function setupParticipatedGame(string $visibility): array
{
    $viewer = User::factory()->create(['profile_complete' => true]);
    $profileUser = User::factory()->create(['profile_complete' => true]);
    $otherOwner = User::factory()->create(['profile_complete' => true]);

    // Mutual follow viewer ↔ profileUser so the viewer reaches the protected tier.
    UserRelationship::follow($viewer, $profileUser);
    UserRelationship::follow($profileUser, $viewer);

    $game = Game::factory()->create([
        'owner_id' => $otherOwner->id,
        'visibility' => $visibility,
        'status' => 'scheduled',
        'date_time' => now()->addDays(5),
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $profileUser->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    return [$viewer, $profileUser, $otherOwner, $game];
}

/**
 * Campaign equivalent of {@see setupParticipatedGame()}.
 *
 * @param  'public'|'protected'|'private'  $visibility
 * @return array{0: User, 1: User, 2: User, 3: Campaign}
 */
function setupParticipatedCampaign(string $visibility): array
{
    $viewer = User::factory()->create(['profile_complete' => true]);
    $profileUser = User::factory()->create(['profile_complete' => true]);
    $otherOwner = User::factory()->create(['profile_complete' => true]);

    UserRelationship::follow($viewer, $profileUser);
    UserRelationship::follow($profileUser, $viewer);

    $campaign = Campaign::factory()->create([
        'owner_id' => $otherOwner->id,
        'visibility' => $visibility,
        'status' => 'active',
    ]);

    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => $profileUser->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    return [$viewer, $profileUser, $otherOwner, $campaign];
}
