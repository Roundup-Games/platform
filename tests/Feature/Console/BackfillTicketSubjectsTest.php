<?php

use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\Review;
use App\Models\User;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Models\TicketSubjectLink;

/*
 * Command test for `tickets:backfill-subjects` — the one-shot migration of
 * legacy ticket metadata entity refs into first-class TicketSubjectLink rows.
 *
 * Fixtures create tickets in the legacy state: entity refs only in metadata,
 * no subjects attached (the state any ticket created before the 2026-06-27
 * Ticket Subjects integration is in). The command should resolve each ref to
 * its model and attach it with the same role label the live path uses.
 */

beforeEach(function () {
    seedRoles();

    Department::firstOrCreate(['name' => 'Safety'], ['is_active' => true]);
    Department::firstOrCreate(['name' => 'Events'], ['is_active' => true]);
});

/** Create a ticket in the legacy state (metadata only, no subjects). */
function legacyTicket(string $ticketType, array $metadata): Ticket
{
    return Ticket::create([
        'requester_type' => User::class,
        'requester_id' => User::factory()->create()->id,
        'subject' => 'Legacy ticket',
        'description' => 'legacy',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Medium->value,
        'ticket_type' => $ticketType,
        'channel' => TicketChannel::Web->value,
        'metadata' => $metadata,
    ]);
}

describe('tickets:backfill-subjects command', function () {

    it('attaches the reported entity for legacy content_report tickets', function () {
        $reportedUser = User::factory()->create();
        $ticket = legacyTicket('content_report', [
            'entity_type' => 'user',
            'entity_id' => $reportedUser->id,
            'entity_name' => $reportedUser->name,
        ]);

        $this->artisan('tickets:backfill-subjects')->assertSuccessful();

        $ticket->refresh();
        expect($ticket->subjects)->toHaveCount(1)
            ->and($ticket->subjects->first()->subject_type)->toBe(User::class)
            ->and($ticket->subjects->first()->subject_id)->toBe($reportedUser->id)
            ->and($ticket->subjects->first()->role)->toBe('reported');
    });

    it('attaches the Game via morph alias (not FQCN) for legacy content_report tickets', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->for($owner, 'owner')->create();
        $ticket = legacyTicket('content_report', [
            'entity_type' => 'game',
            'entity_id' => $game->id,
            'entity_name' => $game->name,
        ]);

        $this->artisan('tickets:backfill-subjects')->assertSuccessful();

        // Game is in Relation::morphMap as 'game' — attachSubject must honor
        // the alias so backfilled subjects match live-created ones.
        $ticket->refresh();
        expect($ticket->subjects->first()->subject_type)->toBe('game')
            ->and($ticket->subjects->first()->subject->is($game))->toBeTrue();
    });

    it('attaches both review and author for legacy review_report tickets', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->for($owner, 'owner')->create();
        $author = User::factory()->create();
        $review = Review::factory()->forReviewable($game)->create(['reviewer_id' => $author->id]);
        $ticket = legacyTicket('review_report', [
            'review_id' => $review->id,
            'review_author_id' => $author->id,
        ]);

        $this->artisan('tickets:backfill-subjects')->assertSuccessful();

        $ticket->refresh();
        expect($ticket->subjects)->toHaveCount(2)
            ->and($ticket->subjects->pluck('role')->sort()->values()->all())->toBe(['author', 'reported']);
    });

    it('attaches the Location for legacy venue_claim tickets', function () {
        $location = Location::factory()->create();
        $ticket = legacyTicket('venue_claim', ['location_id' => $location->id]);

        $this->artisan('tickets:backfill-subjects')->assertSuccessful();

        $ticket->refresh();
        expect($ticket->subjects->first()->subject_type)->toBe(Location::class)
            ->and($ticket->subjects->first()->role)->toBe('venue');
    });

    it('attaches the GameSystem for legacy game_system_request tickets once synced', function () {
        $gameSystem = GameSystem::factory()->create();
        $ticket = legacyTicket('game_system_request', ['game_system_id' => $gameSystem->id]);

        $this->artisan('tickets:backfill-subjects')->assertSuccessful();

        $ticket->refresh();
        expect($ticket->subjects->first()->subject_type)->toBe('game_system')
            ->and($ticket->subjects->first()->role)->toBe('created');
    });

    it('skips venue_proposal tickets whose venue was never created (no location_id)', function () {
        // Unapproved proposals never created a Location — location_id absent.
        $ticket = legacyTicket('venue_proposal', ['venue_name' => 'Never Approved']);

        $this->artisan('tickets:backfill-subjects')->assertSuccessful();

        expect($ticket->refresh()->subjects)->toBeEmpty();
    });

    it('skips tickets whose referenced model was deleted', function () {
        $ticket = legacyTicket('content_report', [
            'entity_type' => 'user',
            'entity_id' => 'deadbeef-0000-0000-0000-000000000000', // nonexistent
            'entity_name' => 'Deleted User',
        ]);

        $this->artisan('tickets:backfill-subjects')->assertSuccessful();

        expect($ticket->refresh()->subjects)->toBeEmpty();
    });

    it('is idempotent — a second run is a no-op', function () {
        $reportedUser = User::factory()->create();
        $ticket = legacyTicket('content_report', [
            'entity_type' => 'user',
            'entity_id' => $reportedUser->id,
        ]);

        $this->artisan('tickets:backfill-subjects')->assertSuccessful();
        $this->artisan('tickets:backfill-subjects')->assertSuccessful();

        expect($ticket->refresh()->subjects)->toHaveCount(1);
    });

    it('reports nothing to do when all tickets already have subjects', function () {
        // A ticket with no entity-bearing ticket_type is already "migrated"
        // from the command's perspective (it's not in scope).
        legacyTicket('account_recovery', ['issue_type' => 'login_issue']);

        $this->artisan('tickets:backfill-subjects')
            ->assertSuccessful()
            ->expectsOutputToContain('All entity-bearing tickets already have subjects');
    });

    it('dry-run attaches nothing but reports what it would do', function () {
        $reportedUser = User::factory()->create();
        $ticket = legacyTicket('content_report', [
            'entity_type' => 'user',
            'entity_id' => $reportedUser->id,
        ]);

        $this->artisan('tickets:backfill-subjects', ['--dry-run' => true])
            ->assertSuccessful();

        expect($ticket->refresh()->subjects)->toBeEmpty();
    });
});
