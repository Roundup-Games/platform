<?php

namespace Tests\Feature\Livewire\GameSystems;

use App\Models\GameSystemRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class RequestGameSystemPageTest extends TestCase
{
    use DatabaseTransactions;
    use SetsUpLocale {
        SetsUpLocale::setUp as setUpLocale;
    }

    private User $user;

    protected function setUp(): void
    {
        $this->setUpLocale();

        $this->user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
    }

    // ── Page Access ────────────────────────────────────

    public function test_guest_is_redirected_from_request_page(): void
    {
        $response = $this->get('/en/game-systems/request');
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_request_page(): void
    {
        $response = $this->actingAs($this->user)->get('/en/game-systems/request');
        $response->assertOk();
    }

    // ── Validation ─────────────────────────────────────

    public function test_name_is_required(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', '')
            ->call('submit')
            ->assertHasErrors(['name' => 'required']);
    }

    public function test_name_max_255_characters(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', str_repeat('x', 256))
            ->call('submit')
            ->assertHasErrors(['name' => 'max']);
    }

    public function test_type_must_be_valid(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Test')
            ->set('type', 'invalid')
            ->call('submit')
            ->assertHasErrors(['type' => 'in']);
    }

    public function test_bgg_url_must_be_valid_url(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Test')
            ->set('bgg_url', 'not-a-url')
            ->call('submit')
            ->assertHasErrors(['bgg_url' => 'url']);
    }

    public function test_valid_submission_passes_validation(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->set('type', 'boardgame')
            ->set('bgg_url', 'https://boardgamegeek.com/boardgame/266192')
            ->set('publisher', 'Stonemaier Games')
            ->set('designer', 'Elizabeth Hargrave')
            ->set('notes', 'Great engine builder')
            ->call('submit')
            ->assertHasNoErrors();
    }

    // ── Submit & Record Creation ───────────────────────

    public function test_submit_creates_game_system_request(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->set('type', 'boardgame')
            ->set('publisher', 'Stonemaier Games')
            ->call('submit');

        $this->assertDatabaseHas('game_system_requests', [
            'user_id' => $this->user->id,
            'name' => 'Wingspan',
            'type' => 'boardgame',
            'publisher' => 'Stonemaier Games',
            'status' => 'pending',
        ]);
    }

    public function test_submit_sets_submitted_flag(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->call('submit')
            ->assertSet('submitted', true);

        // Verify the record was created (substantive success check)
        $this->assertDatabaseHas('game_system_requests', [
            'user_id' => $this->user->id,
            'name' => 'Wingspan',
        ]);
    }

    public function test_submit_trims_name(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', '  Wingspan  ')
            ->call('submit');

        $this->assertDatabaseHas('game_system_requests', [
            'name' => 'Wingspan',
        ]);
    }

    // ── Duplicate Check ────────────────────────────────

    public function test_duplicate_pending_request_is_rejected(): void
    {
        GameSystemRequest::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Wingspan',
            'status' => 'pending',
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->call('submit')
            ->assertHasErrors(['name']);

        // Only the factory-created record should exist
        $this->assertEquals(1, GameSystemRequest::count());
    }

    public function test_duplicate_case_insensitive(): void
    {
        GameSystemRequest::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Wingspan',
            'status' => 'pending',
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'wingspan')
            ->call('submit')
            ->assertHasErrors(['name']);
    }

    public function test_duplicate_in_review_is_rejected(): void
    {
        GameSystemRequest::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Wingspan',
            'status' => 'in_review',
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->call('submit')
            ->assertHasErrors(['name']);
    }

    public function test_approved_request_allows_resubmission(): void
    {
        GameSystemRequest::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Wingspan',
            'status' => 'approved',
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertEquals(2, GameSystemRequest::count());
    }

    public function test_different_user_can_request_same_name(): void
    {
        $otherUser = User::factory()->create(['profile_complete' => true]);

        GameSystemRequest::factory()->create([
            'user_id' => $otherUser->id,
            'name' => 'Wingspan',
            'status' => 'pending',
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\GameSystems\RequestGameSystemPage::class)
            ->set('name', 'Wingspan')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertEquals(2, GameSystemRequest::count());
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

        $this->assertEquals(3, GameSystemRequest::count());
    }

    // ── Observability ──────────────────────────────────

    public function test_duplicate_attempt_is_logged(): void
    {
        GameSystemRequest::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Wingspan',
            'status' => 'pending',
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
            GameSystemRequest::factory()->create([
                'user_id' => $this->user->id,
                'name' => "Game $i",
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
