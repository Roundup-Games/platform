<?php

use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\GmailCanonicalInviteBackfill;
use Illuminate\Support\Facades\DB;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

/*
 * Verifies the Gmail-canonicalization backfill migration:
 *   - non-canonical gmail invitee_email values are rewritten to canonical
 *   - dot-variant duplicates for the same entity are merged (unique index safe)
 *   - non-gmail addresses are untouched
 *   - suppressed invites are untouched
 */

it('backfills non-canonical gmail invite emails', function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

    $p = GameParticipant::create([
        'game_id' => $game->id, 'user_id' => null,
        'invitee_email' => 'alice.smith+roundup@gmail.com',
        'role' => ParticipantRole::Invited->value,
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);

    app(GmailCanonicalInviteBackfill::class)->run();

    expect(DB::table('game_participants')->where('id', $p->id)->value('invitee_email'))
        ->toBe('alicesmith@gmail.com');
});

it('merges dot-variant duplicates and keeps the matched one', function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();
    $matched = User::factory()->create(['email' => 'alicesmith@gmail.com']);

    // Older matched row (dotted) + newer unmatched row (dotless).
    $kept = GameParticipant::create([
        'game_id' => $game->id, 'user_id' => $matched->id,
        'invitee_email' => 'alice.smith@gmail.com',
        'role' => ParticipantRole::Invited->value,
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);
    $dup = GameParticipant::create([
        'game_id' => $game->id, 'user_id' => null,
        'invitee_email' => 'alicesmith@gmail.com',
        'role' => ParticipantRole::Invited->value,
        'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);

    app(GmailCanonicalInviteBackfill::class)->run();

    expect(GameParticipant::where('id', $kept->id)->exists())->toBeTrue('matched row must survive')
        ->and(GameParticipant::where('id', $dup->id)->exists())->toBeFalse('unmatched duplicate must be removed')
        ->and(GameParticipant::where('game_id', $game->id)->where('invitee_email', 'alicesmith@gmail.com')->count())->toBe(1);
});

it('leaves non-gmail and suppressed invites untouched', function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner();

    $yahoo = GameParticipant::create([
        'game_id' => $game->id, 'user_id' => null,
        'invitee_email' => 'dave.work@yahoo.com',
        'role' => ParticipantRole::Invited->value, 'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);
    $suppressed = GameParticipant::create([
        'game_id' => $game->id, 'user_id' => null,
        'invitee_email' => 'suppressed-'.hash('sha256', 'x'),
        'role' => ParticipantRole::Invited->value, 'status' => ParticipantStatus::Pending->value,
        'join_source' => JoinSource::EmailInvite->value,
    ]);

    app(GmailCanonicalInviteBackfill::class)->run();

    expect(DB::table('game_participants')->where('id', $yahoo->id)->value('invitee_email'))->toBe('dave.work@yahoo.com')
        ->and(DB::table('game_participants')->where('id', $suppressed->id)->value('invitee_email'))->toBe($suppressed->invitee_email);
});
