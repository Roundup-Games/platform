<?php

use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\GmailCanonicalInviteBackfill;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

/*
 * Retroactive-linking pass: already-registered users must be associated to
 * pending invites that were never claimed (the invitee signed up before the
 * canonicalization fix, so the registration-time matcher found no match).
 */

it('links an already-registered user to a canonicalized pending invite', function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

    // Pre-fix storage: dotted form.
    $invite = GameParticipant::create([
        'game_id' => $game->id, 'user_id' => null,
        'invitee_email' => 'alice.smith@gmail.com',
        'role' => ParticipantRole::Invited->value,
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);
    // Alice registered (via Google, say) BEFORE the fix, so her email is stored
    // dotless and the registration-time matcher never linked her.
    $alice = User::factory()->create(['email' => 'alicesmith@gmail.com']);

    app(GmailCanonicalInviteBackfill::class)->run();
    $linked = app(GmailCanonicalInviteBackfill::class)->linkExistingUsers();

    expect($linked)->toBe(1)
        ->and(GameParticipant::where('id', $invite->id)->value('user_id'))->toBe($alice->id)
        ->and(GameParticipant::where('id', $invite->id)->value('invitee_email'))->toBe('alicesmith@gmail.com');
});

it('links a user registered under a dotted gmail variant to a dotless invite', function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

    $invite = GameParticipant::create([
        'game_id' => $game->id, 'user_id' => null,
        'invitee_email' => 'alicesmith@gmail.com', // already canonical
        'role' => ParticipantRole::Invited->value,
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);
    // User registered with the dotted form (email/password signup).
    $alice = User::factory()->create(['email' => 'alice.smith@gmail.com']);

    app(GmailCanonicalInviteBackfill::class)->run();
    $linked = app(GmailCanonicalInviteBackfill::class)->linkExistingUsers();

    expect($linked)->toBe(1, 'PHP-side canonical index must catch dotted-form users')
        ->and(GameParticipant::where('id', $invite->id)->value('user_id'))->toBe($alice->id);
});

it('leaves invites with no registered user unlinked', function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

    $invite = GameParticipant::create([
        'game_id' => $game->id, 'user_id' => null,
        'invitee_email' => 'nobody@gmail.com',
        'role' => ParticipantRole::Invited->value,
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);

    app(GmailCanonicalInviteBackfill::class)->run();
    $linked = app(GmailCanonicalInviteBackfill::class)->linkExistingUsers();

    expect($linked)->toBe(0)
        ->and(GameParticipant::where('id', $invite->id)->value('user_id'))->toBeNull();
});

it('skips already-linked and non-pending invites', function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

    $someone = User::factory()->create(['email' => 'linked@gmail.com']);

    // Already linked — must not be touched (re-linking could trip the unique index).
    $linked = GameParticipant::create([
        'game_id' => $game->id, 'user_id' => $someone->id,
        'invitee_email' => 'linked@gmail.com',
        'role' => ParticipantRole::Invited->value,
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);
    // Non-pending — out of scope.
    $rejected = GameParticipant::create([
        'game_id' => $game->id, 'user_id' => null,
        'invitee_email' => 'rejected@gmail.com',
        'role' => ParticipantRole::Invited->value,
        'status' => ParticipantStatus::Rejected->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);
    // Also create a user for rejected to ensure status gating, not user absence, skips it.
    User::factory()->create(['email' => 'rejected@gmail.com']);

    app(GmailCanonicalInviteBackfill::class)->run();
    $count = app(GmailCanonicalInviteBackfill::class)->linkExistingUsers();

    expect($count)->toBe(0)
        ->and(GameParticipant::where('id', $linked->id)->value('user_id'))->toBe($someone->id)
        ->and(GameParticipant::where('id', $rejected->id)->value('user_id'))->toBeNull();
});
