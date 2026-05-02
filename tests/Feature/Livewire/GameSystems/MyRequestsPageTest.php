<?php

namespace Tests\Feature\Livewire\GameSystems;

use App\Livewire\GameSystems\MyRequestsPage;
use App\Models\GameSystem;
use App\Models\GameSystemRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class MyRequestsPageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        URL::defaults(['locale' => 'en']);
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

        $old = GameSystemRequest::factory()->create([
            'user_id' => $user->id,
            'name' => 'Old System',
            'status' => 'approved',
            'created_at' => now()->subDays(2),
        ]);

        $new = GameSystemRequest::factory()->create([
            'user_id' => $user->id,
            'name' => 'New System',
            'status' => 'pending',
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

        GameSystemRequest::factory()->create([
            'user_id' => $user->id,
            'name' => 'My System',
        ]);

        GameSystemRequest::factory()->create([
            'user_id' => $otherUser->id,
            'name' => 'Their System',
        ]);

        Livewire::actingAs($user)
            ->test(MyRequestsPage::class)
            ->assertSee('My System')
            ->assertDontSee('Their System');
    }

    // ── Status Badges ─────────────────────────────────

    public function test_displays_pending_status_badge(): void
    {
        $user = User::factory()->create();

        GameSystemRequest::factory()->create([
            'user_id' => $user->id,
            'name' => 'Test System',
            'status' => 'pending',
        ]);

        Livewire::actingAs($user)
            ->test(MyRequestsPage::class)
            ->assertSee(__('games.request_status_pending'));
    }

    public function test_displays_approved_status_badge(): void
    {
        $user = User::factory()->create();

        GameSystemRequest::factory()->create([
            'user_id' => $user->id,
            'name' => 'Test System',
            'status' => 'approved',
        ]);

        Livewire::actingAs($user)
            ->test(MyRequestsPage::class)
            ->assertSee(__('games.request_status_approved'));
    }

    public function test_displays_rejected_status_badge(): void
    {
        $user = User::factory()->create();

        GameSystemRequest::factory()->create([
            'user_id' => $user->id,
            'name' => 'Test System',
            'status' => 'rejected',
        ]);

        Livewire::actingAs($user)
            ->test(MyRequestsPage::class)
            ->assertSee(__('games.request_status_rejected'));
    }

    public function test_displays_duplicate_status_badge(): void
    {
        $user = User::factory()->create();

        GameSystemRequest::factory()->create([
            'user_id' => $user->id,
            'name' => 'Test System',
            'status' => 'duplicate',
        ]);

        Livewire::actingAs($user)
            ->test(MyRequestsPage::class)
            ->assertSee(__('games.request_status_duplicate'));
    }

    // ── Approved Request Link ─────────────────────────

    public function test_approved_request_links_to_game_system(): void
    {
        $user = User::factory()->create();
        $gameSystem = GameSystem::factory()->create(['slug' => 'wingspan']);

        GameSystemRequest::factory()->create([
            'user_id' => $user->id,
            'name' => 'Wingspan',
            'status' => 'approved',
            'game_system_id' => $gameSystem->id,
        ]);

        Livewire::actingAs($user)
            ->test(MyRequestsPage::class)
            ->assertSee(route('game-systems.show', 'wingspan'));
    }

    public function test_approved_request_without_game_system_shows_name_without_link(): void
    {
        $user = User::factory()->create();

        GameSystemRequest::factory()->create([
            'user_id' => $user->id,
            'name' => 'Orphan System',
            'status' => 'approved',
            'game_system_id' => null,
        ]);

        $component = Livewire::actingAs($user)
            ->test(MyRequestsPage::class);

        // Should see the name as plain text, not as a link to game-system detail
        $component->assertSee('Orphan System');
        $html = $component->html();
        // The back arrow link goes to game-systems index — that's fine.
        // We check that there's no link containing the name as a detail URL.
        $this->assertStringNotContainsString('>Orphan System</a>', $html);
    }

    // ── Rejection Reason ──────────────────────────────

    public function test_rejected_request_shows_rejection_reason(): void
    {
        $user = User::factory()->create();

        GameSystemRequest::factory()->create([
            'user_id' => $user->id,
            'name' => 'Test System',
            'status' => 'rejected',
            'rejection_reason' => 'Already exists in our database.',
        ]);

        Livewire::actingAs($user)
            ->test(MyRequestsPage::class)
            ->assertSee('Already exists in our database.');
    }

    public function test_non_rejected_request_does_not_show_rejection_reason(): void
    {
        $user = User::factory()->create();

        GameSystemRequest::factory()->create([
            'user_id' => $user->id,
            'name' => 'Test System',
            'status' => 'pending',
            'rejection_reason' => 'Should not be visible',
        ]);

        Livewire::actingAs($user)
            ->test(MyRequestsPage::class)
            ->assertDontSee('Should not be visible');
    }

    // ── Pagination ────────────────────────────────────

    public function test_paginates_at_12_per_page(): void
    {
        $user = User::factory()->create();

        // Create 13 requests
        GameSystemRequest::factory()->count(13)->create([
            'user_id' => $user->id,
        ]);

        $component = Livewire::actingAs($user)
            ->test(MyRequestsPage::class);

        // Should have pagination (not all 13 on one page)
        $this->assertCount(12, $component->viewData('requests')->items());
    }

    // ── Type Badge ────────────────────────────────────

    public function test_displays_type_label(): void
    {
        $user = User::factory()->create();

        GameSystemRequest::factory()->create([
            'user_id' => $user->id,
            'name' => 'D&D 5e',
            'type' => 'ttrpg',
            'status' => 'pending',
        ]);

        Livewire::actingAs($user)
            ->test(MyRequestsPage::class)
            ->assertSee(__('games.type_ttrpg'));
    }
}
