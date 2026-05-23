<?php

namespace Tests\Feature\Livewire\GameSystems;

use App\Livewire\GameSystems\MyRequestsPage;
use App\Models\GameSystem;
use App\Models\User;
use Database\Seeders\EscalatedSetupSeeder;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class MyRequestsPageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EscalatedSetupSeeder::class);
    }

    // ── Helper: create a game system request ticket ─────

    private function createGameSystemTicket(User $user, array $overrides = []): Ticket
    {
        $department = Department::where('name', 'Game Systems')->firstOrFail();

        $defaults = [
            'requester_type' => User::class,
            'requester_id' => $user->id,
            'subject' => 'Game System Request: Test System',
            'description' => 'Please add this system.',
            'status' => TicketStatus::Open->value,
            'priority' => 'medium',
            'department_id' => $department->id,
            'ticket_type' => 'game_system_request',
            'channel' => 'web',
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => null,
                'publisher' => null,
                'designer' => null,
                'game_system_type' => 'boardgame',
                'game_system_id' => null,
            ],
        ];

        return Ticket::create(array_merge($defaults, $overrides));
    }

    // ── Page Access ───────────────────────────────────

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/en/game-systems/requests/mine')
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_access_page(): void
    {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/en/game-systems/requests/mine')
            ->assertStatus(200);
    }

    // ── Rendering ─────────────────────────────────────

    public function test_shows_empty_state_when_no_requests(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(MyRequestsPage::class)
            ->assertSee(__('games.content_no_requests_yet'))
            ->assertSee(__('games.action_submit_first_request'));
    }

    public function test_shows_user_requests_sorted_by_newest_first(): void
    {
        $user = User::factory()->create();

        $this->createGameSystemTicket($user, [
            'subject' => 'Game System Request: Old System',
            'created_at' => now()->subDays(2),
        ]);

        $this->createGameSystemTicket($user, [
            'subject' => 'Game System Request: New System',
            'created_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(MyRequestsPage::class)
            ->assertSeeInOrder(['New System', 'Old System']);
    }

    public function test_only_shows_current_users_requests(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->createGameSystemTicket($user, [
            'subject' => 'Game System Request: My System',
        ]);

        $this->createGameSystemTicket($otherUser, [
            'subject' => 'Game System Request: Their System',
        ]);

        Livewire::actingAs($user)
            ->test(MyRequestsPage::class)
            ->assertSee('My System')
            ->assertDontSee('Their System');
    }

    // ── Approved Request Link ─────────────────────────

    public function test_approved_request_links_to_game_system(): void
    {
        $user = User::factory()->create();
        $gameSystem = GameSystem::factory()->create(['slug' => 'wingspan']);

        $this->createGameSystemTicket($user, [
            'subject' => 'Game System Request: Wingspan',
            'status' => TicketStatus::Resolved->value,
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => null,
                'publisher' => null,
                'designer' => null,
                'game_system_type' => 'boardgame',
                'game_system_id' => $gameSystem->id,
            ],
        ]);

        Livewire::actingAs($user)
            ->test(MyRequestsPage::class)
            ->assertSee(route('game-systems.show', 'wingspan'));
    }

    public function test_approved_request_without_game_system_shows_name_without_link(): void
    {
        $user = User::factory()->create();

        $this->createGameSystemTicket($user, [
            'subject' => 'Game System Request: Orphan System',
            'status' => TicketStatus::Resolved->value,
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => null,
                'publisher' => null,
                'designer' => null,
                'game_system_type' => 'boardgame',
                'game_system_id' => null,
            ],
        ]);

        $component = Livewire::actingAs($user)
            ->test(MyRequestsPage::class);

        // Should see the name as plain text, not as a link to game-system detail
        $component->assertSee('Orphan System');
        $html = $component->html();
        $this->assertStringNotContainsString('>Orphan System</a>', $html);
    }

    // ── Rejection Reason ──────────────────────────────

    public function test_rejected_request_shows_rejection_reason(): void
    {
        $user = User::factory()->create();

        $this->createGameSystemTicket($user, [
            'subject' => 'Game System Request: Test System',
            'status' => TicketStatus::Closed->value,
            'metadata' => [
                'game_system_request' => true,
                'bgg_url' => null,
                'publisher' => null,
                'designer' => null,
                'game_system_type' => 'boardgame',
                'game_system_id' => null,
                'close_reason' => 'rejected',
                'rejection_reason' => 'Already exists in our database.',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(MyRequestsPage::class)
            ->assertSee('Already exists in our database.');
    }

    // ── Pagination ────────────────────────────────────

    public function test_paginates_at_12_per_page(): void
    {
        $user = User::factory()->create();

        // Create 13 tickets
        for ($i = 0; $i < 13; $i++) {
            $this->createGameSystemTicket($user, [
                'subject' => "Game System Request: System {$i}",
            ]);
        }

        $component = Livewire::actingAs($user)
            ->test(MyRequestsPage::class);

        // Should have pagination (not all 13 on one page)
        $this->assertCount(12, $component->viewData('requests')->items());
    }
}
