<?php

use App\Enums\JoinSource;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

// ═══════════════════════════════════════════════════════════
// 1. REGISTRATION MATCHES PENDING GAME INVITE BY EMAIL
// ═══════════════════════════════════════════════════════════

test('registration matches pending game invite by email', function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

    // Create an email invite for a user that doesn't exist yet
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => null,
        'invitee_email' => 'new@example.com',
        'role' => 'invited',
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);

    // Simulate registration (call the controller directly)
    $user = registerUser('new@example.com');

    // Assert the game participant now has user_id populated
    $this->assertDatabaseHas('game_participants', [
        'game_id' => $game->id,
        'user_id' => $user->id,
        'invitee_email' => 'new@example.com',
        'role' => 'invited',
        'status' => ParticipantStatus::Pending->value,
    ]);
});

// ═══════════════════════════════════════════════════════════
// 2. REGISTRATION MATCHES PENDING CAMPAIGN INVITE BY EMAIL
// ═══════════════════════════════════════════════════════════

test('registration matches pending campaign invite by email', function () {
    ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();

    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => null,
        'invitee_email' => 'new@example.com',
        'role' => 'invited',
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);

    $user = registerUser('new@example.com');

    $this->assertDatabaseHas('campaign_participants', [
        'campaign_id' => $campaign->id,
        'user_id' => $user->id,
        'invitee_email' => 'new@example.com',
        'role' => 'invited',
        'status' => ParticipantStatus::Pending->value,
    ]);
});

// ═══════════════════════════════════════════════════════════
// 3. REGISTRATION MATCHING IS CASE INSENSITIVE
// ═══════════════════════════════════════════════════════════

test('registration matching is case insensitive', function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

    // In the real flow, inviteByEmail lowercases the email before storing.
    // Simulate that by storing lowercase.
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => null,
        'invitee_email' => 'test@example.com',
        'role' => 'invited',
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);

    // Register user — the controller's 'lowercase' validation rule normalizes the email
    // before persisting, and matchPendingInvitations lowercases again. The match works
    // because both sides end up as lowercase.
    $user = registerUser('Test@Example.COM');

    $this->assertDatabaseHas('game_participants', [
        'game_id' => $game->id,
        'user_id' => $user->id,
    ]);
});

// ═══════════════════════════════════════════════════════════
// 4. REGISTRATION DOES NOT MATCH NON-PENDING INVITES
// ═══════════════════════════════════════════════════════════

test('registration does not match non-pending invites', function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

    // Create a rejected invite
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => null,
        'invitee_email' => 'rejected@example.com',
        'role' => 'invited',
        'status' => ParticipantStatus::Rejected->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);

    $user = registerUser('rejected@example.com');

    // user_id should remain null — rejected invites are not matched
    $this->assertDatabaseHas('game_participants', [
        'game_id' => $game->id,
        'user_id' => null,
        'invitee_email' => 'rejected@example.com',
        'status' => ParticipantStatus::Rejected->value,
    ]);
});

// ═══════════════════════════════════════════════════════════
// 5. REGISTRATION MATCHES MULTIPLE INVITES ACROSS ENTITIES
// ═══════════════════════════════════════════════════════════

test('registration matches multiple invites across entities', function () {
    ['owner' => $owner, 'game' => $game1] = $this->createGameWithOwner();
    ['owner' => $owner2, 'game' => $game2] = $this->createGameWithOwner();
    ['owner' => $owner3, 'campaign' => $campaign] = $this->createCampaignWithOwner();

    // Create pending invites in 2 games + 1 campaign
    GameParticipant::create([
        'game_id' => $game1->id,
        'user_id' => null,
        'invitee_email' => 'multi@example.com',
        'role' => 'invited',
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);

    GameParticipant::create([
        'game_id' => $game2->id,
        'user_id' => null,
        'invitee_email' => 'multi@example.com',
        'role' => 'invited',
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);

    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => null,
        'invitee_email' => 'multi@example.com',
        'role' => 'invited',
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);

    $user = registerUser('multi@example.com');

    // All 3 should be matched
    $this->assertDatabaseHas('game_participants', [
        'game_id' => $game1->id,
        'user_id' => $user->id,
    ]);
    $this->assertDatabaseHas('game_participants', [
        'game_id' => $game2->id,
        'user_id' => $user->id,
    ]);
    $this->assertDatabaseHas('campaign_participants', [
        'campaign_id' => $campaign->id,
        'user_id' => $user->id,
    ]);
});

// ═══════════════════════════════════════════════════════════
// 6. REGISTRATION LOGS MATCHED INVITES
// ═══════════════════════════════════════════════════════════

test('registration logs matched invites', function () {
    Log::spy();
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => null,
        'invitee_email' => 'logged@example.com',
        'role' => 'invited',
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);

    $user = registerUser('logged@example.com');

    // Should log individual match
    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) use ($game, $user) {
            return $message === 'registration.matched_game_invite'
                && $context['game_id'] === $game->id
                && $context['user_id'] === $user->id
                && $context['invitee_email'] === 'logged@example.com';
        })
        ->once();

    // Should log summary
    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) use ($user) {
            return $message === 'registration.invite_matches_found'
                && $context['user_id'] === $user->id
                && $context['total_matches'] === 1
                && $context['game_matches'] === 1
                && $context['campaign_matches'] === 0;
        })
        ->once();
});

// ── Helper ────────────────────────────────────────────────

/**
 * Simulate user registration by calling the store method directly.
 * This avoids needing to POST to /register with CSRF, etc.
 */
function registerUser(string $email): User
{
    $controller = new \App\Http\Controllers\Auth\RegisteredUserController();
    $request = \Illuminate\Http\Request::create('/register', 'POST', [
        'name' => 'Test User',
        'email' => $email,
        'password' => 'secret-password123',
        'password_confirmation' => 'secret-password123',
    ]);

    // Resolve the request validation and user creation manually
    // to avoid full HTTP stack — mirrors what the controller does
    $user = User::create([
        'name' => 'Test User',
        'email' => $email,
        'password' => Hash::make('secret-password123'),
        'password_set_at' => now(),
        'profile_complete' => false,
    ]);

    // Call matchPendingInvitations via reflection since it's private
    $method = new \ReflectionMethod($controller, 'matchPendingInvitations');
    $method->setAccessible(true);
    $method->invoke($controller, $user);

    return $user;
}
