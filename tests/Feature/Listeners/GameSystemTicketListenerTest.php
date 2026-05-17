<?php

namespace Tests\Feature\Listeners;

use App\Enums\NotificationCategory;
use App\Listeners\HandleGameSystemTicketClosed;
use App\Listeners\HandleGameSystemTicketResolved;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\GameSystemRequestApproved;
use App\Notifications\GameSystemRequestDuplicate;
use App\Notifications\GameSystemRequestRejected;
use Database\Seeders\EscalatedSetupSeeder;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Events\TicketClosed as TicketClosedEvent;
use Escalated\Laravel\Events\TicketResolved as TicketResolvedEvent;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class GameSystemTicketListenerTest extends TestCase
{
    use DatabaseTransactions;
    use SetsUpLocale {
        SetsUpLocale::setUp as setUpLocale;
    }

    private User $user;
    private Department $department;

    protected function setUp(): void
    {
        $this->setUpLocale();

        parent::setUp();

        $this->user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $this->seed(EscalatedSetupSeeder::class);
        $this->department = Department::where('name', 'Game Systems')->firstOrFail();
    }

    // ── Helper: create a game system request ticket ─────

    private function createGameSystemTicket(array $overrides = []): Ticket
    {
        $defaults = [
            'requester_type' => User::class,
            'requester_id' => $this->user->id,
            'subject' => 'Game System Request: Wingspan',
            'description' => 'Please add Wingspan to the catalog.',
            'status' => TicketStatus::Open->value,
            'priority' => 'medium',
            'department_id' => $this->department->id,
            'ticket_type' => 'game_system_request',
            'channel' => 'web',
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => null,
                'publisher' => 'Stonemaier Games',
                'designer' => 'Elizabeth Hargrove',
                'game_system_type' => 'boardgame',
                'game_system_id' => null,
            ],
        ];

        return Ticket::create(array_merge($defaults, $overrides));
    }

    // ── Department / ticket_type filtering ─────────────

    public function test_resolved_listener_ignores_non_game_system_tickets(): void
    {
        $ticket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $this->user->id,
            'subject' => 'General support question',
            'description' => 'A general support question.',
            'status' => TicketStatus::Resolved->value,
            'priority' => 'medium',
            'department_id' => $this->department->id,
            'ticket_type' => 'question',
            'channel' => 'web',
            'metadata' => [],
        ]);

        // Should not create any GameSystem
        $gameSystemCountBefore = GameSystem::count();

        $event = new TicketResolvedEvent($ticket, null);
        app(HandleGameSystemTicketResolved::class)->handle($event);

        $this->assertEquals($gameSystemCountBefore, GameSystem::count());
    }

    public function test_resolved_listener_ignores_tickets_in_other_departments(): void
    {
        $otherDepartment = Department::where('name', '!=', 'Game Systems')->first();
        if (! $otherDepartment) {
            $otherDepartment = Department::create(['name' => 'Other Department']);
        }

        $ticket = $this->createGameSystemTicket([
            'department_id' => $otherDepartment->id,
        ]);

        $gameSystemCountBefore = GameSystem::count();

        $event = new TicketResolvedEvent($ticket, null);
        app(HandleGameSystemTicketResolved::class)->handle($event);

        $this->assertEquals($gameSystemCountBefore, GameSystem::count());
    }

    public function test_closed_listener_ignores_non_game_system_tickets(): void
    {
        $ticket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $this->user->id,
            'subject' => 'General support question',
            'description' => 'A general support question.',
            'status' => TicketStatus::Closed->value,
            'priority' => 'medium',
            'department_id' => $this->department->id,
            'ticket_type' => 'question',
            'channel' => 'web',
            'metadata' => [],
        ]);

        $event = new TicketClosedEvent($ticket, null);
        app(HandleGameSystemTicketClosed::class)->handle($event);

        // Should not send any game system request notifications
        $gsrNotifications = $this->user->notifications()
            ->whereIn('type', [
                GameSystemRequestRejected::class,
                GameSystemRequestDuplicate::class,
            ])
            ->count();

        $this->assertEquals(0, $gsrNotifications);
    }

    // ── Approval flow (ticket resolved) ─────────────────

    public function test_approval_creates_game_system_manually(): void
    {
        $ticket = $this->createGameSystemTicket([
            'status' => TicketStatus::Resolved->value,
        ]);

        $event = new TicketResolvedEvent($ticket, null);
        app(HandleGameSystemTicketResolved::class)->handle($event);

        // Verify GameSystem was created
        $gameSystem = GameSystem::where('name', 'Wingspan')->first();
        $this->assertNotNull($gameSystem);
        $this->assertEquals('boardgame', $gameSystem->type);
        $this->assertEquals('manual', $gameSystem->source);
        $this->assertEquals('Please add Wingspan to the catalog.', $gameSystem->description);

        // Verify ticket metadata was updated with game_system_id
        $ticket->refresh();
        $this->assertEquals($gameSystem->id, $ticket->metadata['game_system_id']);
    }

    public function test_approval_sends_approved_notification_to_requester(): void
    {
        $ticket = $this->createGameSystemTicket([
            'status' => TicketStatus::Resolved->value,
        ]);

        $event = new TicketResolvedEvent($ticket, null);
        app(HandleGameSystemTicketResolved::class)->handle($event);

        $gameSystem = GameSystem::where('name', 'Wingspan')->first();
        $this->assertNotNull($gameSystem);

        // Verify notification was sent
        $notification = $this->user->notifications()
            ->where('type', GameSystemRequestApproved::class)
            ->first();

        $this->assertNotNull($notification);
        $this->assertEquals('game_system_request_approved', $notification->data['type']);
        $this->assertEquals($gameSystem->id, $notification->data['game_system_id']);
        $this->assertEquals('Wingspan', $notification->data['game_system_name']);
    }

    public function test_approval_extracts_name_from_subject(): void
    {
        $ticket = $this->createGameSystemTicket([
            'subject' => 'Game System Request: Terraforming Mars',
            'status' => TicketStatus::Resolved->value,
        ]);

        $event = new TicketResolvedEvent($ticket, null);
        app(HandleGameSystemTicketResolved::class)->handle($event);

        $gameSystem = GameSystem::where('name', 'Terraforming Mars')->first();
        $this->assertNotNull($gameSystem);
        $this->assertStringContainsString('terraforming-mars', $gameSystem->slug);
    }

    public function test_approval_uses_correct_game_system_type_from_metadata(): void
    {
        $ticket = $this->createGameSystemTicket([
            'subject' => 'Game System Request: Dungeons & Dragons',
            'status' => TicketStatus::Resolved->value,
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => null,
                'publisher' => 'Wizards of the Coast',
                'designer' => 'Gary Gygax',
                'game_system_type' => 'ttrpg',
                'game_system_id' => null,
            ],
        ]);

        $event = new TicketResolvedEvent($ticket, null);
        app(HandleGameSystemTicketResolved::class)->handle($event);

        $gameSystem = GameSystem::where('name', 'Dungeons & Dragons')->first();
        $this->assertNotNull($gameSystem);
        $this->assertEquals('ttrpg', $gameSystem->type);
    }

    // ── Rejection flow (ticket closed, not duplicate) ───

    public function test_rejection_sends_rejected_notification_with_reason(): void
    {
        $ticket = $this->createGameSystemTicket([
            'status' => TicketStatus::Closed->value,
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => null,
                'publisher' => null,
                'designer' => null,
                'game_system_type' => 'boardgame',
                'game_system_id' => null,
            ],
        ]);

        // Add an internal note as the rejection reason
        $admin = User::factory()->create();
        $ticket->addReply($admin, 'This game already exists in the catalog.', true);

        $event = new TicketClosedEvent($ticket, null);
        app(HandleGameSystemTicketClosed::class)->handle($event);

        // Verify notification was sent
        $notification = $this->user->notifications()
            ->where('type', GameSystemRequestRejected::class)
            ->first();

        $this->assertNotNull($notification);
        $this->assertEquals('game_system_request_rejected', $notification->data['type']);
        $this->assertEquals('This game already exists in the catalog.', $notification->data['rejection_reason']);
        $this->assertEquals('Wingspan', $notification->data['game_system_name']);
    }

    public function test_rejection_sends_notification_without_reason_when_no_internal_note(): void
    {
        $ticket = $this->createGameSystemTicket([
            'status' => TicketStatus::Closed->value,
        ]);

        $event = new TicketClosedEvent($ticket, null);
        app(HandleGameSystemTicketClosed::class)->handle($event);

        $notification = $this->user->notifications()
            ->where('type', GameSystemRequestRejected::class)
            ->first();

        $this->assertNotNull($notification);
        $this->assertNull($notification->data['rejection_reason']);
    }

    // ── Duplicate flow (ticket closed with duplicate metadata) ──

    public function test_duplicate_sends_duplicate_notification(): void
    {
        $existingSystem = GameSystem::factory()->create([
            'name' => 'Wingspan',
            'slug' => 'wingspan',
        ]);

        $ticket = $this->createGameSystemTicket([
            'status' => TicketStatus::Closed->value,
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => null,
                'publisher' => null,
                'designer' => null,
                'game_system_type' => 'boardgame',
                'game_system_id' => null,
                'duplicate_of_game_system_id' => $existingSystem->id,
            ],
        ]);

        $event = new TicketClosedEvent($ticket, null);
        app(HandleGameSystemTicketClosed::class)->handle($event);

        // Verify duplicate notification was sent
        $notification = $this->user->notifications()
            ->where('type', GameSystemRequestDuplicate::class)
            ->first();

        $this->assertNotNull($notification);
        $this->assertEquals('game_system_request_duplicate', $notification->data['type']);
        $this->assertEquals($existingSystem->id, $notification->data['existing_game_system_id']);
        $this->assertEquals('Wingspan', $notification->data['existing_game_system_name']);
        $this->assertEquals('wingspan', $notification->data['existing_game_system_slug']);
    }

    public function test_duplicate_does_not_send_rejection_notification(): void
    {
        $existingSystem = GameSystem::factory()->create([
            'name' => 'Wingspan',
            'slug' => 'wingspan',
        ]);

        $ticket = $this->createGameSystemTicket([
            'status' => TicketStatus::Closed->value,
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => null,
                'publisher' => null,
                'designer' => null,
                'game_system_type' => 'boardgame',
                'game_system_id' => null,
                'duplicate_of_game_system_id' => $existingSystem->id,
            ],
        ]);

        $event = new TicketClosedEvent($ticket, null);
        app(HandleGameSystemTicketClosed::class)->handle($event);

        // Should NOT send rejection notification
        $rejectedNotification = $this->user->notifications()
            ->where('type', GameSystemRequestRejected::class)
            ->first();

        $this->assertNull($rejectedNotification);
    }

    // ── Event listener registration ─────────────────────

    public function test_ticket_resolved_event_dispatches_listener(): void
    {
        Event::fake([TicketResolvedEvent::class]);

        $ticket = $this->createGameSystemTicket([
            'status' => TicketStatus::InProgress->value,
        ]);

        $ticket->markResolved($this->user);

        Event::assertDispatched(TicketResolvedEvent::class, function ($event) use ($ticket) {
            return $event->ticket->id === $ticket->id;
        });
    }

    public function test_ticket_closed_event_dispatches_listener(): void
    {
        Event::fake([TicketClosedEvent::class]);

        $ticket = $this->createGameSystemTicket([
            'status' => TicketStatus::Resolved->value,
        ]);

        $ticket->markClosed($this->user);

        Event::assertDispatched(TicketClosedEvent::class, function ($event) use ($ticket) {
            return $event->ticket->id === $ticket->id;
        });
    }

    // ── BGG URL extraction ──────────────────────────────

    public function test_approval_extracts_bgg_id_from_url_for_sync(): void
    {
        // We mock BggSyncService to avoid making real BGG API calls
        $this->mock(\App\Services\BggSyncService::class, function ($mock) {
            $mock->shouldReceive('syncGameSystems')
                ->once()
                ->with([12345])
                ->andReturn(['synced' => 1, 'failed' => 0, 'errors' => []]);
        });

        // Create a GameSystem that would be returned by BGG sync
        GameSystem::factory()->create([
            'name' => 'Ticket to Ride',
            'slug' => 'ticket-to-ride',
            'bgg_id' => 12345,
        ]);

        $ticket = $this->createGameSystemTicket([
            'subject' => 'Game System Request: Ticket to Ride',
            'status' => TicketStatus::Resolved->value,
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => 'https://boardgamegeek.com/boardgame/12345/ticket-to-ride',
                'publisher' => 'Days of Wonder',
                'designer' => 'Alan R. Moon',
                'game_system_type' => 'boardgame',
                'game_system_id' => null,
            ],
        ]);

        $event = new TicketResolvedEvent($ticket, null);
        app(HandleGameSystemTicketResolved::class)->handle($event);

        // Verify GameSystem was found (synced from BGG)
        $ticket->refresh();
        $this->assertNotNull($ticket->metadata['game_system_id']);
    }

    public function test_approval_falls_back_to_manual_when_bgg_url_has_no_id(): void
    {
        $ticket = $this->createGameSystemTicket([
            'subject' => 'Game System Request: Custom Game',
            'status' => TicketStatus::Resolved->value,
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => 'https://example.com/not-a-bgg-url',
                'publisher' => null,
                'designer' => null,
                'game_system_type' => 'boardgame',
                'game_system_id' => null,
            ],
        ]);

        $event = new TicketResolvedEvent($ticket, null);
        app(HandleGameSystemTicketResolved::class)->handle($event);

        // Should fall back to manual creation
        $gameSystem = GameSystem::where('name', 'Custom Game')->first();
        $this->assertNotNull($gameSystem);
        $this->assertEquals('manual', $gameSystem->source);
    }

    // ── Logging ─────────────────────────────────────────

    public function test_approval_logs_processing(): void
    {
        Log::spy();

        $ticket = $this->createGameSystemTicket([
            'status' => TicketStatus::Resolved->value,
        ]);

        $event = new TicketResolvedEvent($ticket, null);
        app(HandleGameSystemTicketResolved::class)->handle($event);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => str_contains($message, 'Game system ticket resolved'));
    }

    public function test_rejection_logs_processing(): void
    {
        Log::spy();

        $ticket = $this->createGameSystemTicket([
            'status' => TicketStatus::Closed->value,
        ]);

        $event = new TicketClosedEvent($ticket, null);
        app(HandleGameSystemTicketClosed::class)->handle($event);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => str_contains($message, 'Game system ticket closed'));
    }

    public function test_approval_logs_error_on_failure(): void
    {
        Log::spy();

        // Create a ticket in the Game Systems department
        $ticket = $this->createGameSystemTicket([
            'status' => TicketStatus::Resolved->value,
        ]);

        // Mock BggSyncService to throw an exception (simulating sync failure)
        $this->mock(\App\Services\BggSyncService::class, function ($mock) {
            $mock->shouldReceive('syncGameSystems')
                ->andThrow(new \RuntimeException('BGG API is down'));
        });

        // Give the ticket a bgg_url so it triggers BGG sync
        $ticket->updateQuietly([
            'metadata' => array_merge($ticket->metadata ?? [], [
                'bgg_url' => 'https://boardgamegeek.com/boardgame/99999/nonexistent',
            ]),
        ]);

        $event = new TicketResolvedEvent($ticket, null);
        app(HandleGameSystemTicketResolved::class)->handle($event);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => str_contains($message, 'BGG sync failed, falling back to manual creation'));
    }
}
