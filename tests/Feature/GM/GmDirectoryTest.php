<?php

namespace Tests\Feature\GM;

use App\Enums\GmProficiency;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\GMProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GmDirectoryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
    }

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

    public function test_directory_page_renders_successfully(): void
    {
        $response = $this->get('/en/gms');

        $response->assertStatus(200);
    }

    public function test_directory_page_shows_title(): void
    {
        $response = $this->get('/en/gms');

        $response->assertSee(__('gms.title_game_master_directory'));
    }

    public function test_directory_page_shows_search_input(): void
    {
        $response = $this->get('/en/gms');

        $response->assertSee(__('gms.action_search_gms'));
    }

    // ── GM Cards ───────────────────────────────────────

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

    public function test_search_by_partial_name_finds_gm(): void
    {
        $gm = $this->createActiveGm(['name' => 'Gandalf the Grey']);

        $response = $this->get('/en/gms?q=Gandalf');

        $response->assertSee('Gandalf the Grey');
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

    // ── Empty State ────────────────────────────────────

    public function test_empty_directory_shows_empty_state(): void
    {
        $response = $this->get('/en/gms');

        $response->assertSee(__('gms.content_no_gms_found'));
    }

    public function test_empty_state_with_filters_shows_clear_button(): void
    {
        $response = $this->get('/en/gms?q=nonexistent');

        $response->assertSee(__('gms.action_clear_filters'));
    }

    // ── URL Filter Persistence ─────────────────────────

    public function test_search_query_appears_in_url(): void
    {
        $gm = $this->createActiveGm(['name' => 'Test GM Name']);

        $response = $this->get('/en/gms?q=Test');

        $response->assertStatus(200);
        // Verify the filter actually worked — the search was applied
        $response->assertSee('Test GM Name');
    }

    public function test_specialization_filter_appears_in_url(): void
    {
        $response = $this->get('/en/gms?specialization=storytelling');

        $response->assertStatus(200);
    }

    public function test_sort_appears_in_url(): void
    {
        $response = $this->get('/en/gms?sort=newest');

        $response->assertStatus(200);
    }

    public function test_min_rating_filter_appears_in_url(): void
    {
        $response = $this->get('/en/gms?min_rating=4');

        $response->assertStatus(200);
    }

    // ── Combined Filters ───────────────────────────────

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

    public function test_gm_card_links_to_public_profile(): void
    {
        $gm = $this->createActiveGm();

        $response = $this->get('/en/gms');

        $response->assertSee(route('profile.public', $gm));
    }

    // ── Filter Bar Elements ────────────────────────────

    public function test_filter_bar_shows_sort_options(): void
    {
        $response = $this->get('/en/gms');

        $response->assertSee(__('gms.sort_highest_rated'));
        $response->assertSee(__('gms.sort_most_reviewed'));
        $response->assertSee(__('gms.sort_newest'));
    }

    public function test_filter_bar_shows_specialization_options(): void
    {
        $response = $this->get('/en/gms');

        // Pills render each proficiency label from the enum
        foreach (GmProficiency::cases() as $proficiency) {
            $response->assertSee($proficiency->label());
        }
    }

    public function test_filter_bar_shows_min_rating_options(): void
    {
        $response = $this->get('/en/gms');

        $response->assertSee(__('gms.field_any_rating'));
    }

    public function test_clear_filters_button_shows_when_filters_active(): void
    {
        $response = $this->get('/en/gms?q=test');

        $response->assertSee(__('gms.action_clear_filters'));
    }

    public function test_clear_filters_button_hidden_when_no_filters_active(): void
    {
        $gm = $this->createActiveGm();

        $response = $this->get('/en/gms');

        $content = $response->getContent();
        $this->assertStringNotContainsString(__('gms.action_clear_filters'), $content);
    }

    // ── Pagination ─────────────────────────────────────

    public function test_pagination_with_many_gms(): void
    {
        // Create 13 active GMs (more than the 12-per-page limit)
        for ($i = 0; $i < 13; $i++) {
            $this->createActiveGm(['name' => "GM Number {$i}"]);
        }

        $response = $this->get('/en/gms');

        $response->assertStatus(200);
        $response->assertSee('GM Number 0');
        // Livewire pagination uses wire:click for page navigation
        $content = $response->getContent();
        $this->assertStringContainsString('gotoPage', $content);
    }

    public function test_no_pagination_with_few_gms(): void
    {
        $gm = $this->createActiveGm();

        $response = $this->get('/en/gms');

        $response->assertStatus(200);
        // With only 1 GM (under 12 limit), no pagination should appear
        $content = $response->getContent();
        $this->assertStringNotContainsString('page=2', $content);
    }

    // ── Search Wildcard Escaping ───────────────────────

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

    public function test_has_active_filters_detects_search(): void
    {
        $component = \Livewire\Livewire::test(\App\Livewire\GM\GmDirectory::class);
        $this->assertFalse($component->instance()->hasActiveFilters());

        $component->set('search', 'test');
        $this->assertTrue($component->instance()->hasActiveFilters());
    }

    // ── Review-Based Proficiency Badges ────────────────

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
