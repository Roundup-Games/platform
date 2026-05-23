<?php

namespace Tests\Feature\Livewire\GM;

use App\Enums\GmProficiency;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\GMProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GmDirectoryTest extends TestCase
{
    use DatabaseTransactions;

    // ── Helpers ────────────────────────────────────────

    private function createActiveGm(array $userOverrides = [], array $profileOverrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
        ], $userOverrides));

        GMProfile::factory()->create(array_merge([
            'user_id' => $user->id,
            'is_active' => true,
        ], $profileOverrides));

        return $user;
    }

    private function createInactiveGm(array $userOverrides = [], array $profileOverrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'email_verified_at' => now(),
        ], $userOverrides));

        GMProfile::factory()->create(array_merge([
            'user_id' => $user->id,
            'is_active' => false,
        ], $profileOverrides));

        return $user;
    }

    // ── GM Cards ───────────────────────────────────────

    // smoke: core value proposition — active GMs appear in directory
    #[\PHPUnit\Framework\Attributes\Group('smoke')]
    public function test_active_gm_appears_in_directory(): void
    {
        $gm = $this->createActiveGm(['name' => 'Alice the GM']);

        $response = $this->get('/en/gms');

        $response->assertSee('Alice the GM');
    }

    public function test_active_gm_rating_appears_when_present(): void
    {
        $gm = $this->createActiveGm([], [
            'average_rating' => 4.50,
            'review_count' => 10,
        ]);

        $response = $this->get('/en/gms');

        $response->assertSee('4.5');
        $response->assertSee('10');
    }

    public function test_gm_specializations_appear_as_tags(): void
    {
        $gm = $this->createActiveGm([], [
            'specializations' => ['storytelling', 'world-builder'],
        ]);

        $response = $this->get('/en/gms');

        $response->assertSee(GmProficiency::Storytelling->label());
        $response->assertSee(GmProficiency::WorldBuilder->label());
    }

    // ── Filter by Specialization ───────────────────────

    public function test_filter_by_specialization(): void
    {
        $gm1 = $this->createActiveGm([], ['specializations' => ['storytelling']]);
        $gm2 = $this->createActiveGm([], ['specializations' => ['voices']]);

        $response = $this->get('/en/gms?specialization=storytelling');

        $response->assertSee($gm1->name);
        $response->assertDontSee($gm2->name);
    }

    // ── Filter by Game System ──────────────────────────

    public function test_filter_by_game_system(): void
    {
        $system = GameSystem::factory()->create(['name' => ['en' => 'D&D 5e']]);
        $otherSystem = GameSystem::factory()->create(['name' => ['en' => 'Pathfinder']]);

        $gm1 = $this->createActiveGm();
        Game::factory()->create([
            'owner_id' => $gm1->id,
            'game_system_id' => $system->id,
            'status' => 'completed',
        ]);

        $gm2 = $this->createActiveGm();
        Game::factory()->create([
            'owner_id' => $gm2->id,
            'game_system_id' => $otherSystem->id,
            'status' => 'completed',
        ]);

        $response = $this->get('/en/gms?game_system_id=' . $system->id);

        $response->assertSee($gm1->name);
        $response->assertDontSee($gm2->name);
    }

    // ── Filter by Min Rating ───────────────────────────

    public function test_filter_by_min_rating(): void
    {
        $gm1 = $this->createActiveGm([], ['average_rating' => 5.00, 'review_count' => 10]);
        $gm2 = $this->createActiveGm([], ['average_rating' => 4.90, 'review_count' => 8]);
        $gm3 = $this->createActiveGm([], ['average_rating' => 2.50, 'review_count' => 3]);

        // min_rating=4 should show gm1 and gm2 but not gm3
        $response = $this->get('/en/gms?min_rating=4');
        $response->assertSee($gm1->name);
        $response->assertSee($gm2->name);
        $response->assertDontSee($gm3->name);

        // min_rating=5 boundary — only gm1 (exactly 5.0)
        $response5 = $this->get('/en/gms?min_rating=5');
        $response5->assertSee($gm1->name);
        $response5->assertDontSee($gm2->name);
    }

    // ── Sort ───────────────────────────────────────────

    public function test_sort_order_uses_query_not_html_position(): void
    {
        $gm1 = $this->createActiveGm(['name' => 'Low Rated'], ['average_rating' => 3.00, 'review_count' => 1]);
        $gm2 = $this->createActiveGm(['name' => 'High Rated'], ['average_rating' => 4.90, 'review_count' => 10]);
        $gm3 = $this->createActiveGm(['name' => 'Mid Rated'], ['average_rating' => 4.00, 'review_count' => 5]);

        // Default sort (highest_rated) via Livewire component — verify view data order
        $component = \Livewire\Livewire::test(\App\Livewire\GM\GmDirectory::class);
        $gms = $component->viewData('results');

        // Results should be ordered by rating descending (compare by user_id since results are GMProfiles)
        $userIds = $gms->pluck('user_id')->toArray();
        expect($userIds)->toBe([$gm2->id, $gm3->id, $gm1->id]);

        // Sort by most_reviewed
        $component->set('sortBy', 'most_reviewed')->assertSuccessful();
        $gms = $component->viewData('results');
        $userIds = $gms->pluck('user_id')->toArray();
        expect($userIds)->toBe([$gm2->id, $gm3->id, $gm1->id]);

        // Sort by newest
        $gm1->gmProfile->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();
        $gm2->gmProfile->forceFill(['created_at' => now()])->saveQuietly();
        $gm3->gmProfile->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

        $component->set('sortBy', 'newest')->assertSuccessful();
        $gms = $component->viewData('results');
        $userIds = $gms->pluck('user_id')->toArray();
        expect($userIds)->toBe([$gm2->id, $gm3->id, $gm1->id]);
    }

    // ── Non-active GMs Hidden ──────────────────────────

    // smoke: gating works — inactive GMs are filtered out; users without profiles too
    #[\PHPUnit\Framework\Attributes\Group('smoke')]
    public function test_inactive_gm_not_shown_in_directory(): void
    {
        $inactiveGm = $this->createInactiveGm(['name' => 'Inactive GM']);
        $regularUser = User::factory()->create(['name' => 'Regular User']);

        $response = $this->get('/en/gms');

        $response->assertDontSee('Inactive GM');
        $response->assertDontSee('Regular User');
    }

    // ── URL Filter Persistence ─────────────────────────

    public function test_combined_search_and_specialization_filter(): void
    {
        $gm1 = $this->createActiveGm(['name' => 'Alice Storyteller'], ['specializations' => ['storytelling']]);
        $gm2 = $this->createActiveGm(['name' => 'Alice Voices'], ['specializations' => ['voices']]);
        $gm3 = $this->createActiveGm(['name' => 'Bob Storyteller'], ['specializations' => ['storytelling']]);

        $response = $this->get('/en/gms?q=Alice&specialization=storytelling');

        $response->assertSee('Alice Storyteller');
        $response->assertDontSee('Alice Voices');
        $response->assertDontSee('Bob Storyteller');
    }

    // ── GM Card Links ──────────────────────────────────

    public function test_search_with_percent_sign_does_not_match_all(): void
    {
        $gm1 = $this->createActiveGm(['name' => 'Alice']);
        $gm2 = $this->createActiveGm(['name' => 'Bob']);

        $response = $this->get('/en/gms?q=%25');

        // Should show empty state, not all GMs
        $response->assertSee(__('gms.content_no_gms_found'));
    }

    public function test_search_with_underscore_literal(): void
    {
        $gm = $this->createActiveGm(['name' => 'Test_GM']);

        $response = $this->get('/en/gms?q=_');

        $response->assertSee('Test_GM');
    }


    public function test_gm_card_shows_proficiency_badges_from_reviews(): void
    {
        $gm = $this->createActiveGm(['name' => 'Badged GM'], ['specializations' => []]);

        \App\Models\Review::factory()->create([
            'gm_profile_id' => $gm->gmProfile->id,
            'proficiency_tags' => ['storytelling', 'voices'],
            'status' => 'published',
        ]);

        $response = $this->get('/en/gms');

        $response->assertSee('Badged GM');
        $response->assertSee(GmProficiency::Storytelling->label());
        $response->assertSee(GmProficiency::Voices->label());
    }
}
