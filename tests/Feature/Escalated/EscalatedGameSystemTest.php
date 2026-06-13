<?php

namespace Tests\Feature;

use App\Dto\SyncResult;
use App\Listeners\HandleGameSystemTicketClosed;
use App\Listeners\HandleGameSystemTicketResolved;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\GameSystemRequestApproved;
use App\Notifications\GameSystemRequestDuplicate;
use App\Notifications\GameSystemRequestRejected;
use App\Services\BggSyncService;
use App\Services\GameSystemRequestService;
use Database\Seeders\EscalatedSetupSeeder;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Events\TicketClosed;
use Escalated\Laravel\Events\TicketResolved;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end integration tests for the game system request lifecycle
 * using Escalated tickets instead of the legacy GameSystemRequest model.
 *
 * Covers: submission → approval/rejection/duplicate → BGG sync.
 */
class EscalatedGameSystemTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Department $department;

    private GameSystemRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $this->seed(EscalatedSetupSeeder::class);
        $this->department = Department::where('name', 'Game Systems')->firstOrFail();
        $this->service = app(GameSystemRequestService::class);
    }

    // ── Helper: create a game system request ticket via the service ─────

    private function submitRequest(array $overrides = []): Ticket
    {
        $data = array_merge([
            'name' => 'Wingspan',
            'type' => 'boardgame',
            'bgg_url' => null,
            'publisher' => 'Stonemaier Games',
            'designer' => 'Elizabeth Hargrave',
            'notes' => 'Please add Wingspan.',
        ], $overrides);

        return $this->service->createRequest($this->user, $data);
    }

    // ── 1. Submission: ticket created in Game Systems department with custom fields ──

    public function test_user_submits_game_system_request_creates_ticket_in_game_systems_department(): void
    {
        $ticket = $this->submitRequest([
            'name' => 'Wingspan',
            'type' => 'boardgame',
            'bgg_url' => 'https://boardgamegeek.com/boardgame/266192/wingspan',
            'publisher' => 'Stonemaier Games',
            'designer' => 'Elizabeth Hargrave',
        ]);

        // Ticket exists in the correct department
        $this->assertEquals($this->department->id, $ticket->department_id);
        $this->assertEquals('game_system_request', $ticket->ticket_type);
        $this->assertEquals('open', $ticket->status->value);
        $this->assertEquals('web', $ticket->channel->value);
        $this->assertEquals('Game System Request: Wingspan', $ticket->subject);

        // Custom fields stored in metadata
        $this->assertTrue($ticket->metadata['game_system_request']);
        $this->assertEquals('boardgame', $ticket->metadata['game_system_type']);
        $this->assertEquals('https://boardgamegeek.com/boardgame/266192/wingspan', $ticket->metadata['bgg_url']);
        $this->assertEquals('Stonemaier Games', $ticket->metadata['publisher']);
        $this->assertEquals('Elizabeth Hargrave', $ticket->metadata['designer']);
        $this->assertNull($ticket->metadata['game_system_id']);

        // Requester is the authenticated user
        $this->assertEquals(User::class, $ticket->requester_type);
        $this->assertEquals($this->user->id, $ticket->requester_id);

        // BGG sync tag applied when bgg_url provided
        $bggTag = Tag::where('name', 'bgg-sync')->first();
        $this->assertNotNull($bggTag);
        $this->assertTrue($ticket->fresh()->tags->contains($bggTag));
    }

    public function test_submission_without_bgg_url_does_not_apply_tag(): void
    {
        $ticket = $this->submitRequest(['bgg_url' => null]);

        $this->assertCount(0, $ticket->fresh()->tags);
    }

    public function test_submission_logs_creation(): void
    {
        Log::spy();

        $this->submitRequest(['name' => 'Catan']);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => str_contains($message, 'Game system request submitted'));
    }

    // ── 2. Approval: ticket resolved → GameSystem created + notification sent ──

    public function test_ticket_resolved_creates_game_system_and_sends_notification(): void
    {
        $ticket = $this->submitRequest([
            'name' => 'Wingspan',
            'type' => 'boardgame',
        ]);

        // Resolve (approve) the ticket
        Event::forget(TicketResolved::class);
        $ticket->markResolved($this->user);

        // Manually invoke the listener
        $event = new TicketResolved($ticket->fresh(), $this->user);
        app(HandleGameSystemTicketResolved::class)->handle($event);

        // GameSystem was created
        $gameSystem = GameSystem::where('name->en', 'Wingspan')->first();
        $this->assertNotNull($gameSystem, 'Expected GameSystem to be created on approval');
        $this->assertEquals('boardgame', $gameSystem->type);
        $this->assertEquals('manual', $gameSystem->source);
        $this->assertEquals('wingspan', $gameSystem->slug);

        // Ticket metadata updated with game_system_id
        $ticket->refresh();
        $this->assertEquals($gameSystem->id, $ticket->metadata['game_system_id']);

        // Notification sent to requester
        $notification = $this->user->notifications()
            ->where('type', GameSystemRequestApproved::class)
            ->first();

        $this->assertNotNull($notification, 'Expected approval notification');
        $this->assertEquals('game_system_request_approved', $notification->data['type']);
        $this->assertEquals($gameSystem->id, $notification->data['game_system_id']);
        $this->assertEquals('Wingspan', $notification->data['game_system_name']);
    }

    public function test_approval_with_bgg_url_syncs_from_bgg(): void
    {
        // Mock BGG sync
        $this->mock(BggSyncService::class, function ($mock) {
            $mock->shouldReceive('syncGameSystems')
                ->once()
                ->with([266192])
                ->andReturn(new SyncResult(synced: 1, failed: 0, errors: []));
        });

        // Pre-create the GameSystem that would result from sync
        GameSystem::factory()->create([
            'name' => ['en' => 'Wingspan'],
            'slug' => 'wingspan',
            'bgg_id' => 266192,
        ]);

        $ticket = $this->submitRequest([
            'name' => 'Wingspan',
            'bgg_url' => 'https://boardgamegeek.com/boardgame/266192/wingspan',
        ]);

        // Resolve and invoke listener
        Event::forget(TicketResolved::class);
        $ticket->markResolved($this->user);

        $event = new TicketResolved($ticket->fresh(), $this->user);
        app(HandleGameSystemTicketResolved::class)->handle($event);

        // Ticket metadata updated
        $ticket->refresh();
        $this->assertNotNull($ticket->metadata['game_system_id']);
        $this->assertEquals(266192, GameSystem::find($ticket->metadata['game_system_id'])->bgg_id);
    }

    public function test_approval_with_invalid_bgg_url_falls_back_to_manual(): void
    {
        $ticket = $this->submitRequest([
            'name' => 'Custom Game',
            'bgg_url' => 'https://example.com/not-bgg',
        ]);

        Event::forget(TicketResolved::class);
        $ticket->markResolved($this->user);

        $event = new TicketResolved($ticket->fresh(), $this->user);
        app(HandleGameSystemTicketResolved::class)->handle($event);

        // Falls back to manual creation
        $gameSystem = GameSystem::where('name->en', 'Custom Game')->first();
        $this->assertNotNull($gameSystem);
        $this->assertEquals('manual', $gameSystem->source);
    }

    public function test_approval_ignores_non_game_system_tickets(): void
    {
        $otherTicket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $this->user->id,
            'subject' => 'General support question',
            'description' => 'Help me',
            'status' => TicketStatus::Open->value,
            'priority' => 'medium',
            'department_id' => $this->department->id,
            'ticket_type' => 'question',
            'channel' => 'web',
            'metadata' => [],
        ]);

        $countBefore = GameSystem::count();

        $event = new TicketResolved($otherTicket, $this->user);
        app(HandleGameSystemTicketResolved::class)->handle($event);

        $this->assertEquals($countBefore, GameSystem::count());
    }

    // ── 3. Rejection: ticket closed → rejection notification with reason ──

    public function test_ticket_closed_sends_rejection_notification_with_reason(): void
    {
        $ticket = $this->submitRequest(['name' => 'Wingspan']);

        // Add an internal note as rejection reason
        $admin = User::factory()->create();
        $ticket->addReply($admin, 'This game already exists in our catalog.', true);

        // Close the ticket — forget both events to avoid side effects from markResolved
        Event::forget(TicketResolved::class);
        Event::forget(TicketClosed::class);
        $ticket->markResolved($this->user);
        $ticket->markClosed($this->user);

        // Invoke listener
        $event = new TicketClosed($ticket->fresh(), $this->user);
        app(HandleGameSystemTicketClosed::class)->handle($event);

        // Rejection notification sent
        $notification = $this->user->notifications()
            ->where('type', GameSystemRequestRejected::class)
            ->first();

        $this->assertNotNull($notification, 'Expected rejection notification');
        $this->assertEquals('game_system_request_rejected', $notification->data['type']);
        $this->assertEquals('This game already exists in our catalog.', $notification->data['rejection_reason']);
        $this->assertEquals('Wingspan', $notification->data['game_system_name']);

        // Rejection reason stored in metadata
        $ticket->refresh();
        $this->assertEquals('This game already exists in our catalog.', $ticket->metadata['rejection_reason']);
    }

    public function test_rejection_without_internal_note_sends_notification_without_reason(): void
    {
        $ticket = $this->submitRequest();

        Event::forget(TicketResolved::class);
        Event::forget(TicketClosed::class);
        $ticket->markResolved($this->user);
        $ticket->markClosed($this->user);

        $event = new TicketClosed($ticket->fresh(), $this->user);
        app(HandleGameSystemTicketClosed::class)->handle($event);

        $notification = $this->user->notifications()
            ->where('type', GameSystemRequestRejected::class)
            ->first();

        $this->assertNotNull($notification);
        $this->assertNull($notification->data['rejection_reason']);
    }

    // ── 4. Duplicate: ticket closed with duplicate link → duplicate notification ──

    public function test_ticket_closed_as_duplicate_sends_duplicate_notification(): void
    {
        $existingSystem = GameSystem::factory()->create([
            'name' => ['en' => 'Wingspan'],
            'slug' => 'wingspan',
        ]);

        $ticket = $this->submitRequest(['name' => 'Wingspan']);

        // Set duplicate metadata on the ticket
        $metadata = $ticket->metadata;
        $metadata['duplicate_of_game_system_id'] = $existingSystem->id;
        $ticket->updateQuietly(['metadata' => $metadata]);

        // Close the ticket
        Event::forget(TicketResolved::class);
        Event::forget(TicketClosed::class);
        $ticket->markResolved($this->user);
        $ticket->markClosed($this->user);

        // Invoke listener
        $event = new TicketClosed($ticket->fresh(), $this->user);
        app(HandleGameSystemTicketClosed::class)->handle($event);

        // Duplicate notification sent
        $notification = $this->user->notifications()
            ->where('type', GameSystemRequestDuplicate::class)
            ->first();

        $this->assertNotNull($notification, 'Expected duplicate notification');
        $this->assertEquals('game_system_request_duplicate', $notification->data['type']);
        $this->assertEquals($existingSystem->id, $notification->data['existing_game_system_id']);
        $this->assertEquals('Wingspan', $notification->data['existing_game_system_name']);
        $this->assertEquals('wingspan', $notification->data['existing_game_system_slug']);

        // No rejection notification was sent
        $rejectedNotification = $this->user->notifications()
            ->where('type', GameSystemRequestRejected::class)
            ->first();
        $this->assertNull($rejectedNotification);
    }

    public function test_duplicate_with_nonexistent_game_system_logs_error(): void
    {
        Log::spy();

        $ticket = $this->submitRequest();

        $metadata = $ticket->metadata;
        $metadata['duplicate_of_game_system_id'] = Str::uuid()->toString();
        $ticket->updateQuietly(['metadata' => $metadata]);

        Event::forget(TicketResolved::class);
        Event::forget(TicketClosed::class);
        $ticket->markResolved($this->user);
        $ticket->markClosed($this->user);

        $event = new TicketClosed($ticket->fresh(), $this->user);
        app(HandleGameSystemTicketClosed::class)->handle($event);

        // No game system notification sent since game system doesn't exist
        $gsrNotifications = $this->user->notifications()
            ->whereIn('type', [
                GameSystemRequestApproved::class,
                GameSystemRequestRejected::class,
                GameSystemRequestDuplicate::class,
            ])
            ->count();
        $this->assertEquals(0, $gsrNotifications);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'Duplicate game system not found'));
    }

    // ── 5. BGG sync action creates GameSystem from bgg_url ──

    public function test_bgg_sync_action_creates_game_system_from_ticket(): void
    {
        $this->mock(BggSyncService::class, function ($mock) {
            $mock->shouldReceive('syncGameSystems')
                ->once()
                ->with([12345])
                ->andReturn(new SyncResult(synced: 1, failed: 0, errors: []));
        });

        // Pre-create GameSystem that BGG sync would produce
        GameSystem::factory()->create([
            'name' => ['en' => 'Ticket to Ride'],
            'slug' => 'ticket-to-ride',
            'bgg_id' => 12345,
        ]);

        $ticket = $this->submitRequest([
            'name' => 'Ticket to Ride',
            'bgg_url' => 'https://boardgamegeek.com/boardgame/12345/ticket-to-ride',
        ]);

        // Simulate admin triggering BGG sync via Filament action
        $gameSystem = $this->service->syncBggFromTicket($ticket);

        $this->assertEquals('Ticket to Ride', $gameSystem->name);
        $this->assertEquals(12345, $gameSystem->bgg_id);

        // Ticket metadata updated
        $ticket->refresh();
        $this->assertEquals($gameSystem->id, $ticket->metadata['game_system_id']);
    }

    public function test_bgg_sync_throws_for_non_game_system_ticket(): void
    {
        $nonGsrTicket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $this->user->id,
            'subject' => 'Help',
            'description' => 'desc',
            'status' => TicketStatus::Open->value,
            'priority' => 'medium',
            'department_id' => $this->department->id,
            'ticket_type' => 'question',
            'channel' => 'web',
            'metadata' => [],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ticket is not a game system request.');

        $this->service->syncBggFromTicket($nonGsrTicket);
    }

    public function test_manual_creation_action_creates_game_system_without_bgg(): void
    {
        $ticket = $this->submitRequest([
            'name' => 'My Custom RPG',
            'type' => 'ttrpg',
        ]);

        $gameSystem = $this->service->createManualFromTicket($ticket);

        $this->assertEquals('My Custom RPG', $gameSystem->name);
        $this->assertEquals('my-custom-rpg', $gameSystem->slug);
        $this->assertEquals('ttrpg', $gameSystem->type);
        $this->assertEquals('manual', $gameSystem->source);

        $ticket->refresh();
        $this->assertEquals($gameSystem->id, $ticket->metadata['game_system_id']);
    }

    // ── 6. Full lifecycle: submit → approve → game system exists ──

    public function test_full_lifecycle_submit_approve_game_system_exists(): void
    {
        // Step 1: User submits request
        $ticket = $this->submitRequest([
            'name' => 'Catan',
            'type' => 'boardgame',
            'publisher' => 'Kosmos',
            'designer' => 'Klaus Teuber',
            'notes' => 'The classic trading game.',
        ]);

        $this->assertEquals('open', $ticket->status->value);
        $this->assertEquals('game_system_request', $ticket->ticket_type);

        // Step 2: Admin resolves (approves) the ticket
        Event::forget(TicketResolved::class);
        $ticket->markResolved($this->user);

        $event = new TicketResolved($ticket->fresh(), $this->user);
        app(HandleGameSystemTicketResolved::class)->handle($event);

        // Step 3: GameSystem exists
        $gameSystem = GameSystem::where('name->en', 'Catan')->first();
        $this->assertNotNull($gameSystem);
        $this->assertEquals('boardgame', $gameSystem->type);
        $this->assertEquals('manual', $gameSystem->source);

        // Step 4: Ticket metadata has game_system_id
        $ticket->refresh();
        $this->assertEquals($gameSystem->id, $ticket->metadata['game_system_id']);

        // Step 5: User received approval notification
        $notification = $this->user->notifications()
            ->where('type', GameSystemRequestApproved::class)
            ->first();
        $this->assertNotNull($notification);
        $this->assertEquals($gameSystem->id, $notification->data['game_system_id']);
    }

    public function test_full_lifecycle_submit_reject_user_notified(): void
    {
        // Step 1: Submit
        $ticket = $this->submitRequest(['name' => 'Bad Game']);

        // Step 2: Admin adds rejection reason and closes
        $admin = User::factory()->create();
        $ticket->addReply($admin, 'Not appropriate for our catalog.', true);

        Event::forget(TicketResolved::class);
        Event::forget(TicketClosed::class);
        $ticket->markResolved($this->user);
        $ticket->markClosed($this->user);

        $event = new TicketClosed($ticket->fresh(), $this->user);
        app(HandleGameSystemTicketClosed::class)->handle($event);

        // Step 3: No GameSystem created
        $this->assertNull(GameSystem::where('name->en', 'Bad Game')->first());

        // Step 4: User received rejection notification
        $notification = $this->user->notifications()
            ->where('type', GameSystemRequestRejected::class)
            ->first();
        $this->assertNotNull($notification);
        $this->assertEquals('Not appropriate for our catalog.', $notification->data['rejection_reason']);
        $this->assertEquals('Bad Game', $notification->data['game_system_name']);
    }

    public function test_full_lifecycle_submit_duplicate_user_redirected(): void
    {
        $existingSystem = GameSystem::factory()->create([
            'name' => ['en' => 'Wingspan'],
            'slug' => 'wingspan',
        ]);

        // Step 1: Submit
        $ticket = $this->submitRequest(['name' => 'Wingspan']);

        // Step 2: Admin marks as duplicate and closes
        $metadata = $ticket->metadata;
        $metadata['duplicate_of_game_system_id'] = $existingSystem->id;
        $ticket->updateQuietly(['metadata' => $metadata]);

        Event::forget(TicketResolved::class);
        Event::forget(TicketClosed::class);
        $ticket->markResolved($this->user);
        $ticket->markClosed($this->user);

        $event = new TicketClosed($ticket->fresh(), $this->user);
        app(HandleGameSystemTicketClosed::class)->handle($event);

        // Step 3: User received duplicate notification
        $notification = $this->user->notifications()
            ->where('type', GameSystemRequestDuplicate::class)
            ->first();
        $this->assertNotNull($notification);
        $this->assertEquals($existingSystem->id, $notification->data['existing_game_system_id']);
        $this->assertEquals('Wingspan', $notification->data['existing_game_system_name']);

        // No approval or rejection notification
        $this->assertEquals(0, $this->user->notifications()
            ->whereIn('type', [GameSystemRequestApproved::class, GameSystemRequestRejected::class])
            ->count());
    }
}
