<?php

use App\Models\GameParticipant;
use App\Models\User;
use App\Services\ParticipantService;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

/*
 * Regression coverage for the Gmail-canonicalization fix.
 *
 * Gmail ignores dots and "+suffix" in the local part and treats
 * @googlemail.com as identical to @gmail.com. An invite typed as
 * "alice.smith@gmail.com" must be claimable by a Google signup that returns
 * "alicesmith@gmail.com". See App\Services\EmailCanonicalizer.
 */

function googleSignup(string $email): User
{
    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn(uniqid('g-', false));
    $socialiteUser->shouldReceive('getEmail')->andReturn($email);
    $socialiteUser->shouldReceive('getName')->andReturn('Google User');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    test()->get('/auth/google/callback');

    return User::where('email', $email)->firstOrFail();
}

// Real invite path (ParticipantService stores canonical) + Google signup with
// a different dot form must link.
it('claims a real invite when Google returns a different Gmail dot form', function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

    $result = app(ParticipantService::class)->inviteByEmail($game, $owner, 'alice.smith@gmail.com');
    expect($result->success)->toBeTrue("invite should succeed: {$result->errorKey}");

    $user = googleSignup('alicesmith@gmail.com');

    expect(GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->exists())
        ->toBeTrue('Gmail dot variants must link to the same invite');
});

it('claims a real invite across @googlemail.com and @gmail.com', function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

    app(ParticipantService::class)->inviteByEmail($game, $owner, 'bob@googlemail.com');

    $user = googleSignup('bob@gmail.com');

    expect(GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->exists())
        ->toBeTrue('@googlemail.com and @gmail.com are the same Gmail mailbox');
});

it('claims a real invite despite a Gmail "+suffix" difference', function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

    app(ParticipantService::class)->inviteByEmail($game, $owner, 'carol+roundup@gmail.com');

    $user = googleSignup('carol@gmail.com');

    expect(GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->exists())
        ->toBeTrue('Gmail "+suffix" must not block invite claiming');
});

it('does not collapse dots for non-Gmail providers', function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

    // Yahoo and most providers treat dots as significant — these are distinct.
    app(ParticipantService::class)->inviteByEmail($game, $owner, 'dave.work@yahoo.com');

    $user = googleSignup('davework@yahoo.com');

    // Distinct mailboxes: must NOT link.
    expect(GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->exists())
        ->toBeFalse('non-Gmail dots are significant and must not collapse');
});

it('deduplicates Gmail dot-variant invites to the same entity', function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

    $first = app(ParticipantService::class)->inviteByEmail($game, $owner, 'eve.smith@gmail.com');
    $second = app(ParticipantService::class)->inviteByEmail($game, $owner, 'evesmith@gmail.com');

    expect($first->success)->toBeTrue()
        ->and($second->success)->toBeFalse('the dot-variant invite should be detected as a duplicate');

    expect(GameParticipant::where('game_id', $game->id)->whereNull('user_id')->count())
        ->toBe(1, 'only one invite row should exist for the Gmail mailbox');
});
