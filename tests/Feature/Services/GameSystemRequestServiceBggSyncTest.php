<?php

namespace Tests\Feature\Services;

use App\Models\GameSystem;
use App\Models\User;
use App\Services\BggSyncService;
use App\Services\GameSystemRequestService;
use Database\Seeders\EscalatedSetupSeeder;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GameSystemRequestServiceBggSyncTest extends TestCase
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

    // ── isGameSystemRequestTicket ───────────────────────

    public function test_identifies_game_system_request_ticket(): void
    {
        $ticket = $this->createGameSystemTicket();

        $this->assertTrue($this->service->isGameSystemRequestTicket($ticket));
    }

    public function test_rejects_non_game_system_ticket_type(): void
    {
        $ticket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $this->user->id,
            'subject' => 'General support question',
            'description' => 'A general support question.',
            'status' => TicketStatus::Open->value,
            'priority' => 'medium',
            'department_id' => $this->department->id,
            'ticket_type' => 'question',
            'channel' => 'web',
            'metadata' => [],
        ]);

        $this->assertFalse($this->service->isGameSystemRequestTicket($ticket));
    }

    public function test_rejects_wrong_department(): void
    {
        $otherDepartment = Department::where('name', '!=', 'Game Systems')->first();
        if (! $otherDepartment) {
            $otherDepartment = Department::create(['name' => 'Other Department']);
        }

        $ticket = $this->createGameSystemTicket([
            'department_id' => $otherDepartment->id,
        ]);

        $this->assertFalse($this->service->isGameSystemRequestTicket($ticket));
    }

    // ── extractBggId ────────────────────────────────────

    public function test_extracts_bgg_id_from_standard_url(): void
    {
        $this->assertEquals(12345, $this->service->extractBggId(
            'https://boardgamegeek.com/boardgame/12345/ticket-to-ride'
        ));
    }

    public function test_extracts_bgg_id_from_url_without_slug(): void
    {
        $this->assertEquals(67890, $this->service->extractBggId(
            'https://boardgamegeek.com/boardgame/67890'
        ));
    }

    public function test_extracts_bgg_id_from_expansion_url(): void
    {
        $this->assertEquals(11111, $this->service->extractBggId(
            'https://boardgamegeek.com/boardgameexpansion/11111/expansion-name'
        ));
    }

    public function test_extracts_bgg_id_from_accessory_url(): void
    {
        $this->assertEquals(22222, $this->service->extractBggId(
            'https://boardgamegeek.com/boardgameaccessory/22222/accessory-name'
        ));
    }

    public function test_returns_null_for_non_bgg_url(): void
    {
        $this->assertNull($this->service->extractBggId('https://example.com/not-bgg'));
    }

    // ── extractName ─────────────────────────────────────

    public function test_extracts_name_from_prefixed_subject(): void
    {
        $ticket = $this->createGameSystemTicket([
            'subject' => 'Game System Request: Terraforming Mars',
        ]);

        $this->assertEquals('Terraforming Mars', $this->service->extractName($ticket));
    }

    public function test_extracts_name_from_subject_without_prefix(): void
    {
        $ticket = $this->createGameSystemTicket([
            'subject' => 'Just a Name',
        ]);

        $this->assertEquals('Just a Name', $this->service->extractName($ticket));
    }

    // ── syncBggFromTicket ───────────────────────────────

    public function test_sync_bgg_from_ticket_creates_game_system_and_updates_metadata(): void
    {
        // Mock BggSyncService
        $this->mock(BggSyncService::class, function ($mock) {
            $mock->shouldReceive('syncGameSystems')
                ->once()
                ->with([12345])
                ->andReturn(['synced' => 1, 'failed' => 0, 'errors' => []]);
        });

        // Pre-create the GameSystem that would result from sync
        GameSystem::factory()->create([
            'name' => ['en' => 'Ticket to Ride'],
            'slug' => 'ticket-to-ride',
            'bgg_id' => 12345,
        ]);

        $ticket = $this->createGameSystemTicket([
            'subject' => 'Game System Request: Ticket to Ride',
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => 'https://boardgamegeek.com/boardgame/12345/ticket-to-ride',
                'publisher' => 'Days of Wonder',
                'designer' => 'Alan R. Moon',
                'game_system_type' => 'boardgame',
                'game_system_id' => null,
            ],
        ]);

        $gameSystem = $this->service->syncBggFromTicket($ticket);

        $this->assertEquals('Ticket to Ride', $gameSystem->name);
        $this->assertEquals(12345, $gameSystem->bgg_id);

        // Verify metadata was updated
        $ticket->refresh();
        $this->assertEquals($gameSystem->id, $ticket->metadata['game_system_id']);
    }

    public function test_sync_bgg_from_ticket_throws_for_non_game_system_ticket(): void
    {
        $ticket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $this->user->id,
            'subject' => 'General question',
            'description' => 'Not a game system request.',
            'status' => TicketStatus::Open->value,
            'priority' => 'medium',
            'department_id' => $this->department->id,
            'ticket_type' => 'question',
            'channel' => 'web',
            'metadata' => [],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ticket is not a game system request.');

        $this->service->syncBggFromTicket($ticket);
    }

    public function test_sync_bgg_from_ticket_throws_for_missing_bgg_url(): void
    {
        $ticket = $this->createGameSystemTicket([
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => null,
                'publisher' => null,
                'designer' => null,
                'game_system_type' => 'boardgame',
                'game_system_id' => null,
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ticket has no BGG URL in metadata.');

        $this->service->syncBggFromTicket($ticket);
    }

    public function test_sync_bgg_from_ticket_throws_for_invalid_bgg_url(): void
    {
        $ticket = $this->createGameSystemTicket([
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => 'https://example.com/not-bgg',
                'publisher' => null,
                'designer' => null,
                'game_system_type' => 'boardgame',
                'game_system_id' => null,
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot extract BGG ID from URL');

        $this->service->syncBggFromTicket($ticket);
    }

    public function test_sync_bgg_from_ticket_throws_when_sync_fails(): void
    {
        $this->mock(BggSyncService::class, function ($mock) {
            $mock->shouldReceive('syncGameSystems')
                ->once()
                ->with([99999])
                ->andReturn(['synced' => 0, 'failed' => 1, 'errors' => ['API timeout']]);
        });

        $ticket = $this->createGameSystemTicket([
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => 'https://boardgamegeek.com/boardgame/99999/does-not-exist',
                'publisher' => null,
                'designer' => null,
                'game_system_type' => 'boardgame',
                'game_system_id' => null,
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('BGG sync failed: API timeout');

        $this->service->syncBggFromTicket($ticket);
    }

    public function test_sync_bgg_from_ticket_logs_success(): void
    {
        Log::spy();

        $this->mock(BggSyncService::class, function ($mock) {
            $mock->shouldReceive('syncGameSystems')
                ->andReturn(['synced' => 1, 'failed' => 0, 'errors' => []]);
        });

        GameSystem::factory()->create([
            'name' => ['en' => 'Wingspan'],
            'slug' => 'wingspan',
            'bgg_id' => 12345,
        ]);

        $ticket = $this->createGameSystemTicket([
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => 'https://boardgamegeek.com/boardgame/12345/wingspan',
                'publisher' => null,
                'designer' => null,
                'game_system_type' => 'boardgame',
                'game_system_id' => null,
            ],
        ]);

        $this->service->syncBggFromTicket($ticket);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => str_contains($message, 'BGG sync triggered from ticket'));

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => str_contains($message, 'BGG sync from ticket completed'));
    }

    // ── createManualFromTicket ──────────────────────────

    public function test_create_manual_from_ticket_creates_game_system(): void
    {
        $ticket = $this->createGameSystemTicket([
            'subject' => 'Game System Request: Custom Game',
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => null,
                'publisher' => null,
                'designer' => null,
                'game_system_type' => 'ttrpg',
                'game_system_id' => null,
            ],
        ]);

        $gameSystem = $this->service->createManualFromTicket($ticket);

        $this->assertEquals('Custom Game', $gameSystem->name);
        $this->assertEquals('custom-game', $gameSystem->slug);
        $this->assertEquals('ttrpg', $gameSystem->type);
        $this->assertEquals('manual', $gameSystem->source);
        $this->assertEquals('Please add Wingspan to the catalog.', $gameSystem->description);

        // Verify metadata was updated
        $ticket->refresh();
        $this->assertEquals($gameSystem->id, $ticket->metadata['game_system_id']);
    }

    public function test_create_manual_from_ticket_throws_for_non_game_system_ticket(): void
    {
        $ticket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $this->user->id,
            'subject' => 'General question',
            'description' => 'Not a game system request.',
            'status' => TicketStatus::Open->value,
            'priority' => 'medium',
            'department_id' => $this->department->id,
            'ticket_type' => 'question',
            'channel' => 'web',
            'metadata' => [],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ticket is not a game system request.');

        $this->service->createManualFromTicket($ticket);
    }

    // ── Integration: auto-sync on approval still works ───

    public function test_auto_sync_on_approval_still_works_through_listener(): void
    {
        // This test verifies the refactored HandleGameSystemTicketResolved
        // still works correctly using the service methods

        $this->mock(BggSyncService::class, function ($mock) {
            $mock->shouldReceive('syncGameSystems')
                ->once()
                ->with([12345])
                ->andReturn(['synced' => 1, 'failed' => 0, 'errors' => []]);
        });

        GameSystem::factory()->create([
            'name' => ['en' => 'Wingspan'],
            'slug' => 'wingspan',
            'bgg_id' => 12345,
        ]);

        $ticket = $this->createGameSystemTicket([
            'status' => TicketStatus::Resolved->value,
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => 'https://boardgamegeek.com/boardgame/12345/wingspan',
                'publisher' => 'Stonemaier Games',
                'designer' => 'Elizabeth Hargrove',
                'game_system_type' => 'boardgame',
                'game_system_id' => null,
            ],
        ]);

        $event = new \Escalated\Laravel\Events\TicketResolved($ticket, null);
        app(\App\Listeners\HandleGameSystemTicketResolved::class)->handle($event);

        // Verify GameSystem was found via BGG sync
        $ticket->refresh();
        $this->assertNotNull($ticket->metadata['game_system_id']);
        $this->assertEquals(12345, GameSystem::find($ticket->metadata['game_system_id'])->bgg_id);
    }

    public function test_auto_manual_creation_on_approval_still_works(): void
    {
        $ticket = $this->createGameSystemTicket([
            'status' => TicketStatus::Resolved->value,
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => null,
                'publisher' => 'Stonemaier Games',
                'designer' => 'Elizabeth Hargrove',
                'game_system_type' => 'boardgame',
                'game_system_id' => null,
            ],
        ]);

        $event = new \Escalated\Laravel\Events\TicketResolved($ticket, null);
        app(\App\Listeners\HandleGameSystemTicketResolved::class)->handle($event);

        $gameSystem = GameSystem::where('name->en', 'Wingspan')->first();
        $this->assertNotNull($gameSystem);
        $this->assertEquals('manual', $gameSystem->source);

        $ticket->refresh();
        $this->assertEquals($gameSystem->id, $ticket->metadata['game_system_id']);
    }
}
