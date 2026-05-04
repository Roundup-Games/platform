<?php

namespace Tests\Feature\GM;

use App\Enums\GmProficiency;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\GMProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class GmDirectoryTest extends TestCase
{
    use DatabaseTransactions;
    use SetsUpLocale;

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

    // ── Page Renders ───────────────────────────────────

    // smoke: paid-feature landing page renders
    #[\PHPUnit\Framework\Attributes\Group('smoke')]
    public function test_directory_page_renders_successfully(): void
    {
        $response = $this->get('/en/gms');

        $response->assertStatus(200);
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

    public function test_active_gm_bio_appears_in_card(): void
    {
        $gm = $this->createActiveGm([], ['bio' => 'Experienced dungeon master']);

        $response = $this->get('/en/gms');

        $response->assertSee('Experienced dungeon master');
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

    public function test_gm_without_rating_shows_no_reviews_text(): void
    {
        $gm = $this->createActiveGm([], [
            'average_rating' => null,
            'review_count' => 0,
        ]);

        $response = $this->get('/en/gms');

        $response->assertSee(__('profile.gm_profile_no_reviews'));
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

    // ── Search ─────────────────────────────────────────

    public function test_search_by_name_finds_matching_gm(): void
    {
        $gm1 = $this->createActiveGm(['name' => 'Magnus Stoneforge']);
        $gm2 = $this->createActiveGm(['name' => 'Elena Nightwhisper']);

        $response = $this->get('/en/gms?q=Magnus');

        $response->assertSee('Magnus Stoneforge');
        $response->assertDontSee('Elena Nightwhisper');
    }

    public function test_search_with_no_results_shows_empty_state(): void
    {
        $gm = $this->createActiveGm(['name' => 'Someone']);

        $response = $this->get('/en/gms?q=NonExistentGM12345');

        $response->assertSee(__('gms.content_no_gms_found'));
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

    public function test_filter_by_specialization_with_no_results(): void
    {
        $gm = $this->createActiveGm([], ['specializations' => ['voices']]);

        $response = $this->get('/en/gms?specialization=creativity');

        $response->assertSee(__('gms.content_no_gms_found'));
    }

    // ── Filter by Game System ──────────────────────────

    public function test_filter_by_game_system(): void
    {
        $system = GameSystem::factory()->create(['name' => 'D&D 5e']);
        $otherSystem = GameSystem::factory()->create(['name' => 'Pathfinder']);

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
        $gm1 = $this->createActiveGm([], ['average_rating' => 4.80, 'review_count' => 5]);
        $gm2 = $this->createActiveGm([], ['average_rating' => 2.50, 'review_count' => 3]);

        $response = $this->get('/en/gms?min_rating=4');

        $response->assertSee($gm1->name);
        $response->assertDontSee($gm2->name);
    }

    public function test_filter_by_min_rating_5(): void
    {
        $gm1 = $this->createActiveGm([], ['average_rating' => 5.00, 'review_count' => 10]);
        $gm2 = $this->createActiveGm([], ['average_rating' => 4.90, 'review_count' => 8]);

        $response = $this->get('/en/gms?min_rating=5');

        $response->assertSee($gm1->name);
        $response->assertDontSee($gm2->name);
    }

    // ── Sort ───────────────────────────────────────────

    public function test_sort_by_highest_rated_default(): void
    {
        $gm1 = $this->createActiveGm([], ['average_rating' => 3.00, 'review_count' => 1]);
        $gm2 = $this->createActiveGm([], ['average_rating' => 4.90, 'review_count' => 10]);

        $response = $this->get('/en/gms');

        $content = $response->getContent();
        $pos1 = strpos($content, $gm1->name);
        $pos2 = strpos($content, $gm2->name);
        // Higher rated GM should appear first
        $this->assertNotFalse($pos1);
        $this->assertNotFalse($pos2);
        $this->assertLessThan($pos1, $pos2);
    }

    public function test_sort_by_most_reviewed(): void
    {
        $gm1 = $this->createActiveGm([], ['review_count' => 2, 'average_rating' => 5.00]);
        $gm2 = $this->createActiveGm([], ['review_count' => 50, 'average_rating' => 3.00]);

        $response = $this->get('/en/gms?sort=most_reviewed');

        $content = $response->getContent();
        $pos1 = strpos($content, $gm1->name);
        $pos2 = strpos($content, $gm2->name);
        $this->assertLessThan($pos1, $pos2);
    }

    public function test_sort_by_newest(): void
    {
        $gm1 = $this->createActiveGm([], ['created_at' => now()->subDays(30)]);
        $gm2 = $this->createActiveGm([], ['created_at' => now()]);

        $response = $this->get('/en/gms?sort=newest');

        $content = $response->getContent();
        $pos1 = strpos($content, $gm1->name);
        $pos2 = strpos($content, $gm2->name);
        $this->assertLessThan($pos1, $pos2);
    }

    // ── Non-active GMs Hidden ──────────────────────────

    // smoke: gating works — inactive GMs are filtered out
    #[\PHPUnit\Framework\Attributes\Group('smoke')]
    public function test_inactive_gm_not_shown_in_directory(): void
    {
        $inactiveGm = $this->createInactiveGm(['name' => 'Inactive GM']);

        $response = $this->get('/en/gms');

        $response->assertDontSee('Inactive GM');
    }

    public function test_user_without_gm_profile_not_shown(): void
    {
        $regularUser = User::factory()->create(['name' => 'Regular User']);

        $response = $this->get('/en/gms');

        $response->assertDontSee('Regular User');
    }

    // ── URL Filter Persistence ─────────────────────────

    public function test_empty_state_with_filters_shows_clear_button(): void
    {
        $response = $this->get('/en/gms?q=nonexistent');

        $response->assertSee(__('gms.action_clear_filters'));
    }

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

    // ── Livewire Component Filter State ────────────────

    public function test_clear_filters_resets_all_livewire_state(): void
    {
        $component = \Livewire\Livewire::test(\App\Livewire\GM\GmDirectory::class)
            ->set('search', 'test')
            ->set('specialization', 'storytelling')
            ->set('min_rating', 4)
            ->call('clearFilters');

        $component
            ->assertSet('search', '')
            ->assertSet('specialization', null)
            ->assertSet('min_rating', null)
            ->assertSet('sortBy', 'highest_rated');
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
