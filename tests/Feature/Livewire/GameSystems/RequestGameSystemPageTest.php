<?php

namespace Tests\Feature\Livewire\GameSystems;

use App\Models\User;
use App\Services\GameSystemRequestService;
use Database\Seeders\EscalatedSetupSeeder;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class RequestGameSystemPageTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        // Seed Escalated departments and tags needed for ticket creation
        $this->seed(EscalatedSetupSeeder::class);
    }

    // ── Validation ─────────────────────────────────────

    public function test_type_must_be_valid(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Test')
            ->set('type', 'invalid')
            ->call('submit')
            ->assertHasErrors(['type' => 'in']);
    }

    // ── Submit & Ticket Creation ───────────────────────

    public function test_submit_creates_escalated_ticket(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->set('type', 'boardgame')
            ->set('publisher', 'Stonemaier Games')
            ->call('submit');

        $ticket = Ticket::where('requester_id', $this->user->id)
            ->where('ticket_type', 'game_system_request')
            ->first();

        $this->assertNotNull($ticket, 'Expected an Escalated ticket to be created');
        $this->assertEquals('Game System Request: Wingspan', $ticket->subject);
        $this->assertEquals('Stonemaier Games', $ticket->metadata['publisher']);
        $this->assertEquals('boardgame', $ticket->metadata['game_system_type']);
    }

    public function test_submit_sets_submitted_flag(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->call('submit')
            ->assertSet('submitted', true);

        // Verify the ticket was created
        $this->assertEquals(1, Ticket::where('ticket_type', 'game_system_request')->count());
    }

    public function test_submit_trims_name(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', '  Wingspan  ')
            ->call('submit');

        $this->assertDatabaseHas('escalated_tickets', [
            'subject' => 'Game System Request: Wingspan',
        ]);
    }

    public function test_submit_sets_department_to_game_systems(): void
    {
        $department = Department::where('name', 'Game Systems')->first();

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->call('submit');

        $ticket = Ticket::where('ticket_type', 'game_system_request')->first();
        $this->assertEquals($department->id, $ticket->department_id);
    }

    public function test_submit_stores_custom_fields_in_metadata(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->set('type', 'boardgame')
            ->set('bgg_url', 'https://boardgamegeek.com/boardgame/266192')
            ->set('publisher', 'Stonemaier Games')
            ->set('designer', 'Elizabeth Hargrave')
            ->call('submit');

        $ticket = Ticket::where('ticket_type', 'game_system_request')->first();

        $this->assertEquals('boardgame', $ticket->metadata['game_system_type']);
        $this->assertEquals('https://boardgamegeek.com/boardgame/266192', $ticket->metadata['bgg_url']);
        $this->assertEquals('Stonemaier Games', $ticket->metadata['publisher']);
        $this->assertEquals('Elizabeth Hargrave', $ticket->metadata['designer']);
    }

    public function test_submit_applies_bgg_sync_tag_when_bgg_url_provided(): void
    {
        $bggTag = Tag::where('name', 'bgg-sync')->first();

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->set('bgg_url', 'https://boardgamegeek.com/boardgame/266192')
            ->call('submit');

        $ticket = Ticket::where('ticket_type', 'game_system_request')->first();
        $this->assertTrue($ticket->tags->contains($bggTag));
    }

    public function test_submit_does_not_apply_bgg_sync_tag_when_no_bgg_url(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->call('submit');

        $ticket = Ticket::where('ticket_type', 'game_system_request')->first();
        $this->assertCount(0, $ticket->tags);
    }

    public function test_submit_sets_metadata_flag(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->call('submit');

        $ticket = Ticket::where('ticket_type', 'game_system_request')->first();
        $this->assertTrue($ticket->metadata['game_system_request'] ?? false);
    }

    public function test_submit_stores_null_fields_as_null_in_metadata(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->set('type', 'boardgame')
            ->call('submit');

        $ticket = Ticket::where('ticket_type', 'game_system_request')->first();
        $this->assertNull($ticket->metadata['bgg_url']);
        $this->assertNull($ticket->metadata['publisher']);
        $this->assertNull($ticket->metadata['designer']);
        $this->assertNull($ticket->metadata['game_system_id']);
    }

    // ── Duplicate Check ────────────────────────────────

    public function test_duplicate_pending_request_is_rejected(): void
    {
        $service = app(GameSystemRequestService::class);
        $service->createRequest($this->user, [
            'name' => 'Wingspan',
            'type' => 'boardgame',
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->call('submit')
            ->assertHasErrors(['name']);

        // Only the first ticket should exist
        $this->assertEquals(1, Ticket::where('ticket_type', 'game_system_request')->count());
    }

    public function test_duplicate_case_insensitive(): void
    {
        $service = app(GameSystemRequestService::class);
        $service->createRequest($this->user, [
            'name' => 'Wingspan',
            'type' => 'boardgame',
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'wingspan')
            ->call('submit')
            ->assertHasErrors(['name']);
    }

    public function test_approved_request_allows_resubmission(): void
    {
        $service = app(GameSystemRequestService::class);
        $ticket = $service->createRequest($this->user, [
            'name' => 'ApprovedGame123',
            'type' => 'boardgame',
        ]);

        // Resolve (approve) the ticket — suppress listener side effects
        \Illuminate\Support\Facades\Event::forget(\Escalated\Laravel\Events\TicketResolved::class);
        $ticket->markResolved($this->user);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'ApprovedGame123')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertEquals(2, Ticket::where('ticket_type', 'game_system_request')->count());
    }

    public function test_different_user_can_request_same_name(): void
    {
        $otherUser = User::factory()->create(['profile_complete' => true]);
        $service = app(GameSystemRequestService::class);
        $service->createRequest($otherUser, [
            'name' => 'Wingspan',
            'type' => 'boardgame',
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertEquals(2, Ticket::where('ticket_type', 'game_system_request')->count());
    }

    // ── Rate Limiting ──────────────────────────────────

    public function test_rate_limit_blocks_fourth_request(): void
    {
        RateLimiter::clear('game-system-request:' . $this->user->id);

        // Create 3 requests
        for ($i = 0; $i < 3; $i++) {
            Livewire::actingAs($this->user)
                ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
                ->set('name', "Game $i")
                ->call('submit')
                ->assertHasNoErrors();
        }

        // 4th should be rate limited
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Game 4')
            ->call('submit')
            ->assertHasErrors(['name']);

        $this->assertEquals(3, Ticket::where('ticket_type', 'game_system_request')->count());
    }

    // ── Observability ──────────────────────────────────

    public function test_duplicate_attempt_is_logged(): void
    {
        $service = app(GameSystemRequestService::class);
        $service->createRequest($this->user, [
            'name' => 'Wingspan',
            'type' => 'boardgame',
        ]);

        $this->actingAs($this->user);

        $spy = \Log::spy();

        Livewire::test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->call('submit');

        $spy->shouldHaveReceived('info', function ($message, $context) {
            return str_contains($message, 'duplicate')
                && ($context['user_id'] ?? null) === $this->user->id
                && ($context['name'] ?? null) === 'Wingspan';
        });
    }

    public function test_rate_limit_hit_is_logged(): void
    {
        RateLimiter::clear('game-system-request:' . $this->user->id);

        // Exhaust the limit
        for ($i = 0; $i < 3; $i++) {
            $service = app(GameSystemRequestService::class);
            $service->createRequest($this->user, [
                'name' => "Game $i",
                'type' => 'boardgame',
            ]);
            RateLimiter::hit('game-system-request:' . $this->user->id);
        }

        $spy = \Log::spy();

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Game 4')
            ->call('submit');

        $spy->shouldHaveReceived('info', function ($message, $context) {
            return str_contains($message, 'rate limit')
                && ($context['user_id'] ?? null) === $this->user->id;
        });
    }

    public function test_successful_submission_is_logged(): void
    {
        $spy = \Log::spy();

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->call('submit');

        $spy->shouldHaveReceived('info', function ($message, $context) {
            return str_contains($message, 'submitted')
                && ($context['user_id'] ?? null) === $this->user->id
                && ($context['name'] ?? null) === 'Wingspan';
        });
    }
}
