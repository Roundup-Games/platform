<?php

namespace Tests\Feature\Services;

use App\Dto\JoinIntent;
use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Exceptions\SignupCutoffReached;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\ShortLink;
use App\Models\User;
use App\Services\ParticipantLifecycle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the unified {@see ParticipantLifecycle::createOrReactivate()} — the
 * single source of truth for participant creation across web (joinViaShareLink)
 * and Discord (ProcessDiscordRsvp).
 *
 * The core invariant: a departed participant (status=Rejected from depart())
 * keeps its row for audit, so a rejoin must UPDATE the row rather than INSERT a
 * duplicate (which would violate the unique (game_id, user_id) constraint).
 */
class ParticipantLifecycleCreateOrReactivateTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function creates_a_new_participant_when_none_exists(): void
    {
        [$owner, $game] = $this->gameWithOwner();
        $joiner = User::factory()->create();

        $participant = app(ParticipantLifecycle::class)->createOrReactivate([
            'game_id' => $game->id,
            'user_id' => $joiner->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'approved_at' => now(),
        ]);

        $this->assertSame(ParticipantStatus::Approved, $participant->status);
        $this->assertDatabaseCount('game_participants', 2); // owner + joiner
    }

    #[Test]
    public function reactivates_a_departed_participant_instead_of_inserting_a_duplicate(): void
    {
        [$owner, $game] = $this->gameWithOwner();
        $joiner = User::factory()->create();

        // Join → leave → rejoin cycle.
        $participant = app(ParticipantLifecycle::class)->createOrReactivate([
            'game_id' => $game->id,
            'user_id' => $joiner->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'approved_at' => now(),
        ]);

        app(ParticipantLifecycle::class)->depart($participant, $joiner);
        $this->assertSame(ParticipantStatus::Rejected, $participant->fresh()->status);
        $this->assertDatabaseCount('game_participants', 2);

        // Rejoin — must reactivate the departed row, not throw a unique violation.
        $reactivated = app(ParticipantLifecycle::class)->createOrReactivate([
            'game_id' => $game->id,
            'user_id' => $joiner->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'approved_at' => now(),
        ]);

        $this->assertSame(ParticipantStatus::Approved, $reactivated->status);
        $this->assertSame($participant->id, $reactivated->id, 'must be the same row, not a new one');
        $this->assertDatabaseCount('game_participants', 2);
    }

    #[Test]
    public function preserves_row_count_across_multiple_join_leave_cycles(): void
    {
        [$owner, $game] = $this->gameWithOwner();
        $joiner = User::factory()->create();

        foreach (range(1, 3) as $i) {
            $p = app(ParticipantLifecycle::class)->createOrReactivate([
                'game_id' => $game->id,
                'user_id' => $joiner->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
                'approved_at' => now(),
            ]);
            app(ParticipantLifecycle::class)->depart($p, $joiner);
        }

        // Three join/leave cycles → still just 2 rows (owner + the one reactive row).
        $this->assertDatabaseCount('game_participants', 2);
    }

    #[Test]
    public function reactivate_overwrites_all_supplied_attributes(): void
    {
        [$owner, $game] = $this->gameWithOwner();
        $joiner = User::factory()->create();

        $participant = app(ParticipantLifecycle::class)->createOrReactivate([
            'game_id' => $game->id,
            'user_id' => $joiner->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'approved_at' => now(),
        ]);
        app(ParticipantLifecycle::class)->depart($participant, $joiner);

        // Rejoin as waitlisted — the reactivated row must reflect the new status.
        $reactivated = app(ParticipantLifecycle::class)->createOrReactivate([
            'game_id' => $game->id,
            'user_id' => $joiner->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now(),
        ]);

        $this->assertSame(ParticipantStatus::Waitlisted, $reactivated->fresh()->status);
        $this->assertNotNull($reactivated->fresh()->waitlisted_at);
    }

    #[Test]
    public function reactivate_resets_departure_and_attendance_fields_so_a_reversed_departure_is_not_mis_scored(): void
    {
        [$owner, $game] = $this->gameWithOwner();
        $joiner = User::factory()->create();

        $participant = app(ParticipantLifecycle::class)->createOrReactivate([
            'game_id' => $game->id,
            'user_id' => $joiner->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'approved_at' => now(),
        ]);

        // Departure near game time stamps attendance_status (reliability weight).
        app(ParticipantLifecycle::class)->depart($participant, $joiner);

        $departed = $participant->fresh();
        $this->assertNotNull($departed->removed_at);
        $this->assertNotNull($departed->removed_by);
        $this->assertSame($joiner->id, $departed->removed_by);
        // depart() scores Approved departures near game time as LateCancel/CancelledEarly.
        $this->assertNotNull($departed->attendance_status, 'precondition: departure stamped attendance_status');

        // Rejoin must reactivate the row AND clear the departure/audit/scoring fields.
        $reactivated = app(ParticipantLifecycle::class)->createOrReactivate([
            'game_id' => $game->id,
            'user_id' => $joiner->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'approved_at' => now(),
        ]);

        $this->assertSame($participant->id, $reactivated->id);
        $this->assertSame(ParticipantStatus::Approved, $reactivated->fresh()->status);
        $this->assertNull($reactivated->fresh()->removed_at, 'removed_at must be cleared on reactivation');
        $this->assertNull($reactivated->fresh()->removed_by, 'removed_by must be cleared on reactivation');
        $this->assertNull($reactivated->fresh()->attendance_status, 'attendance_status must be cleared so the reversed departure is not scored');
    }

    // ── Join-intake (JoinIntent) semantics ─────────────────────────────
    //
    // The typed intent path absorbs what each creation path previously did
    // inline: the signup cutoff guard (D124, now extended to every
    // user-initiated join — the share-intent path gains it in T03), overflow
    // routing via OverflowRouter, approved_at stamping per isFillable, and the
    // reactivation reset base. The tests below pin those semantics at the
    // lifecycle level using synthetic intents, so later re-routing tasks
    // (T02–T04) can prove parity.

    #[Test]
    public function user_initiated_join_is_refused_when_the_signup_cutoff_has_passed(): void
    {
        [$owner, $game] = $this->gameWithOwner();
        $game->update(['signup_cutoff_at' => now()->subHour()]);
        $joiner = User::factory()->create();

        Log::shouldReceive('info')
            ->with('participant.join_refused', \Mockery::on(fn (array $ctx): bool => $ctx['reason'] === 'signup_closed'
                && $ctx['game_id'] === $game->id
                && $ctx['user_id'] === $joiner->id
                && $ctx['join_source'] === JoinSource::ShareLink->value))
            ->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $threw = false;
        try {
            app(ParticipantLifecycle::class)->createOrReactivate(
                new JoinIntent(
                    entity: $game,
                    user: $joiner,
                    joinSource: JoinSource::ShareLink,
                    userInitiated: true,
                ),
            );
        } catch (SignupCutoffReached $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'a user-initiated join past the cutoff must be refused');
        $this->assertDatabaseCount('game_participants', 1); // owner only — no row written
    }

    #[Test]
    public function user_initiated_join_is_allowed_when_the_cutoff_is_in_the_future(): void
    {
        [$owner, $game] = $this->gameWithOwner();
        $game->update(['signup_cutoff_at' => now()->addDay()]);
        $joiner = User::factory()->create();

        $participant = app(ParticipantLifecycle::class)->createOrReactivate(
            new JoinIntent(
                entity: $game,
                user: $joiner,
                joinSource: JoinSource::ShareLink,
                userInitiated: true,
            ),
        );

        $this->assertSame(ParticipantStatus::Approved, $participant->fresh()->status);
        $this->assertDatabaseCount('game_participants', 2);
    }

    #[Test]
    public function host_initiated_invites_are_exempt_from_the_signup_cutoff(): void
    {
        [$owner, $game] = $this->gameWithOwner();
        $game->update(['signup_cutoff_at' => now()->subHour()]);
        $invitee = User::factory()->create();

        $participant = app(ParticipantLifecycle::class)->createOrReactivate(
            JoinIntent::invite($game, $invitee),
        );

        $this->assertSame(ParticipantStatus::Pending, $participant->fresh()->status);
        $this->assertSame(ParticipantRole::Invited, $participant->fresh()->role);
        $this->assertDatabaseCount('game_participants', 2);
    }

    #[Test]
    public function direct_join_stamps_role_status_approved_at_and_source_attribution(): void
    {
        [$owner, $game] = $this->gameWithOwner();
        $joiner = User::factory()->create();

        $shortLink = ShortLink::create([
            'code' => 'joinme',
            'url' => url("/g/{$game->id}"),
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $owner->id,
        ]);

        Log::shouldReceive('info')
            ->with('participant.joined', \Mockery::on(fn (array $ctx): bool => $ctx['game_id'] === $game->id
                && $ctx['user_id'] === $joiner->id
                && $ctx['join_source'] === JoinSource::ShortLink->value
                && $ctx['status'] === ParticipantStatus::Approved->value
                && $ctx['short_link_id'] === $shortLink->id))
            ->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes(); // observer/dashboard-cache side channels

        $participant = app(ParticipantLifecycle::class)->createOrReactivate(
            new JoinIntent(
                entity: $game,
                user: $joiner,
                joinSource: JoinSource::ShortLink,
                userInitiated: true,
                shortLinkId: $shortLink->id,
            ),
        );

        $fresh = $participant->fresh();
        $this->assertSame(ParticipantRole::Player, $fresh->role);
        $this->assertSame(ParticipantStatus::Approved, $fresh->status);
        $this->assertNotNull($fresh->approved_at, 'approved_at must be stamped on direct joins for LIFO demotion ordering');
        $this->assertSame(JoinSource::ShortLink, $fresh->join_source);
        $this->assertSame($shortLink->id, $fresh->short_link_id);
    }

    #[Test]
    public function overflow_parity_full_non_bench_entity_waitlists(): void
    {
        [$owner, $game] = $this->fullGameWithOwner();
        $joiner = User::factory()->create();

        $participant = app(ParticipantLifecycle::class)->createOrReactivate(
            new JoinIntent(
                entity: $game,
                user: $joiner,
                joinSource: JoinSource::ShareLink,
                userInitiated: true,
            ),
        );

        $fresh = $participant->fresh();
        $this->assertSame(ParticipantStatus::Waitlisted, $fresh->status);
        $this->assertSame(ParticipantRole::Player, $fresh->role);
        $this->assertNotNull($fresh->waitlisted_at);
        $this->assertNull($fresh->approved_at, 'overflow rows carry no approved_at');
    }

    #[Test]
    public function overflow_parity_full_bench_mode_entity_benches(): void
    {
        [$owner, $game] = $this->fullGameWithOwner(['bench_mode' => true]);
        $joiner = User::factory()->create();

        $participant = app(ParticipantLifecycle::class)->createOrReactivate(
            new JoinIntent(
                entity: $game,
                user: $joiner,
                joinSource: JoinSource::ShareLink,
                userInitiated: true,
            ),
        );

        $fresh = $participant->fresh();
        $this->assertSame(ParticipantStatus::Benched, $fresh->status);
        $this->assertSame(ParticipantRole::Player, $fresh->role);
        $this->assertNotNull($fresh->benched_at);
    }

    #[Test]
    public function campaign_entities_dispatch_through_the_contract_seam(): void
    {
        [$owner, $campaign] = $this->campaignWithOwner();
        $joiner = User::factory()->create();

        $participant = app(ParticipantLifecycle::class)->createOrReactivate(
            new JoinIntent(
                entity: $campaign,
                user: $joiner,
                joinSource: JoinSource::ShareLink,
                userInitiated: true,
            ),
        );

        $this->assertInstanceOf(CampaignParticipant::class, $participant);
        $this->assertSame(ParticipantStatus::Approved, $participant->fresh()->status);
        $this->assertSame(ParticipantRole::Player, $participant->fresh()->role);
        $this->assertSame(JoinSource::ShareLink, $participant->fresh()->join_source);
        $this->assertDatabaseCount('campaign_participants', 2); // owner + joiner
    }

    #[Test]
    public function campaign_rejoin_after_a_declined_invitation_reactivates_the_rejected_row(): void
    {
        // Campaigns keep Rejected rows (a declined invitation routes through
        // depart()), so a later user-initiated join must UPDATE that row — the
        // direct CampaignParticipant::create the share-link campaign leg used
        // before the join-intake re-route hit the unique (campaign_id, user_id)
        // index instead.
        [$owner, $campaign] = $this->campaignWithOwner();
        $joiner = User::factory()->create();

        $invited = app(ParticipantLifecycle::class)->createOrReactivate(
            JoinIntent::invite($campaign, $joiner),
        );
        app(ParticipantLifecycle::class)->declineInvitation($invited, $joiner);

        $this->assertSame(ParticipantStatus::Rejected, $invited->fresh()->status);
        $this->assertNotNull($invited->fresh()->removed_at);

        $rejoined = app(ParticipantLifecycle::class)->createOrReactivate(
            new JoinIntent(
                entity: $campaign,
                user: $joiner,
                joinSource: JoinSource::ShareLink,
                userInitiated: true,
            ),
        );

        $this->assertSame($invited->id, $rejoined->id, 'must reactivate the rejected row, not insert a duplicate');
        $this->assertSame(ParticipantStatus::Approved, $rejoined->fresh()->status);
        $this->assertSame(ParticipantRole::Player, $rejoined->fresh()->role);
        $this->assertNull($rejoined->fresh()->removed_at, 'the decline audit trail must not leak onto the reactivated row');
        $this->assertSame(JoinSource::ShareLink, $rejoined->fresh()->join_source);
        $this->assertDatabaseCount('campaign_participants', 2); // owner + the one reactivated row
    }

    #[Test]
    public function application_to_a_protected_entity_creates_a_pending_applicant(): void
    {
        [$owner, $game] = $this->gameWithOwner();
        $game->update(['visibility' => 'protected']);
        $applicant = User::factory()->create();

        $participant = app(ParticipantLifecycle::class)->createOrReactivate(
            JoinIntent::application($game, $applicant),
        );

        $fresh = $participant->fresh();
        $this->assertSame(ParticipantRole::Applicant, $fresh->role);
        $this->assertSame(ParticipantStatus::Pending, $fresh->status);
        $this->assertSame(JoinSource::Application, $fresh->join_source);
    }

    #[Test]
    public function application_to_a_public_entity_with_room_auto_approves(): void
    {
        [$owner, $game] = $this->gameWithOwner();
        $applicant = User::factory()->create();

        $participant = app(ParticipantLifecycle::class)->createOrReactivate(
            JoinIntent::application($game, $applicant),
        );

        $fresh = $participant->fresh();
        $this->assertSame(ParticipantRole::Player, $fresh->role);
        $this->assertSame(ParticipantStatus::Approved, $fresh->status);
        $this->assertNotNull($fresh->approved_at);
        $this->assertSame(JoinSource::Application, $fresh->join_source);
    }

    #[Test]
    public function application_to_a_full_public_entity_routes_to_overflow(): void
    {
        [$owner, $game] = $this->fullGameWithOwner();
        $applicant = User::factory()->create();

        $participant = app(ParticipantLifecycle::class)->createOrReactivate(
            JoinIntent::application($game, $applicant),
        );

        $fresh = $participant->fresh();
        $this->assertSame(ParticipantRole::Player, $fresh->role);
        $this->assertSame(ParticipantStatus::Waitlisted, $fresh->status);
        $this->assertNotNull($fresh->waitlisted_at);
    }

    #[Test]
    public function intent_join_reactivates_a_departed_row_with_the_reset_contract(): void
    {
        [$owner, $game] = $this->gameWithOwner();
        $joiner = User::factory()->create();

        $first = app(ParticipantLifecycle::class)->createOrReactivate(
            new JoinIntent(entity: $game, user: $joiner, joinSource: JoinSource::ShareLink, userInitiated: true),
        );
        app(ParticipantLifecycle::class)->depart($first, $joiner);

        $rejoined = app(ParticipantLifecycle::class)->createOrReactivate(
            new JoinIntent(entity: $game, user: $joiner, joinSource: JoinSource::Discord, userInitiated: true),
        );

        $this->assertSame($first->id, $rejoined->id, 'must reactivate the departed row, not insert a duplicate');
        $this->assertSame(ParticipantStatus::Approved, $rejoined->fresh()->status);
        $this->assertNull($rejoined->fresh()->removed_at);
        $this->assertNull($rejoined->fresh()->attendance_status);
        $this->assertSame(JoinSource::Discord, $rejoined->fresh()->join_source, 'rejoin overwrites the source attribution');
        $this->assertDatabaseCount('game_participants', 2);
    }

    #[Test]
    public function email_invitee_rows_key_on_invitee_email_not_a_null_user(): void
    {
        [$owner, $game] = $this->gameWithOwner();

        app(ParticipantLifecycle::class)->createOrReactivate(
            JoinIntent::invite($game, inviteeEmail: 'alice@example.com'),
        );
        app(ParticipantLifecycle::class)->createOrReactivate(
            JoinIntent::invite($game, inviteeEmail: 'bob@example.com'),
        );

        $this->assertDatabaseCount('game_participants', 3); // owner + two distinct invitees
        $this->assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'invitee_email' => 'alice@example.com',
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('game_participants', [
            'game_id' => $game->id,
            'invitee_email' => 'bob@example.com',
        ]);
    }

    #[Test]
    public function an_intent_requires_a_user_or_an_invitee_email(): void
    {
        [$owner, $game] = $this->gameWithOwner();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('user or an invitee email');

        new JoinIntent(
            entity: $game,
            joinSource: JoinSource::ShareLink,
            userInitiated: true,
        );
    }

    #[Test]
    public function a_user_initiated_intent_requires_a_join_source_for_attribution(): void
    {
        [$owner, $game] = $this->gameWithOwner();
        $joiner = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a join source');

        new JoinIntent(
            entity: $game,
            joinSource: null,
            userInitiated: true,
            user: $joiner,
        );
    }

    private function gameWithOwner(): array
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => null,
            'max_players' => 4,
            'min_players' => 2,
            'status' => GameStatus::Scheduled->value,
            'visibility' => 'public',
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        return [$owner, $game];
    }

    private function campaignWithOwner(): array
    {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'max_players' => 4,
        ]);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        return [$owner, $campaign];
    }

    /**
     * A game whose only approved participant is the owner, so one more join
     * overflows (max_players=1) or fits (max_players>=2).
     */
    private function fullGameWithOwner(array $overrides = []): array
    {
        [$owner, $game] = $this->gameWithOwner();
        $game->update(array_merge(['max_players' => 1], $overrides));

        return [$owner, $game->fresh()];
    }
}
