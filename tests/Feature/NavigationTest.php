<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
    }

    public function test_people_nav_link_appears_in_sidebar(): void
    {
        $response = $this->actingAs($this->user)->get('/en/dashboard');

        $response->assertStatus(200);
        $response->assertSee(route('people'));
        $response->assertSee(__('profile.nav_people'));
    }

    public function test_people_nav_link_uses_group_icon(): void
    {
        $response = $this->actingAs($this->user)->get('/en/dashboard');

        $response->assertStatus(200);
        $content = $response->getContent();
        // The "group" Material Symbol icon should appear (mobile + desktop)
        $this->assertStringContainsString('>group</span>', $content);
    }

    public function test_people_nav_has_active_state_on_people_page(): void
    {
        $response = $this->actingAs($this->user)->get('/en/people');

        $response->assertStatus(200);
        $content = $response->getContent();
        // Active state: the group icon should have FILL variation set
        // Blade renders style attributes with HTML entities
        $this->assertMatchesRegularExpression("/FILL.*1.*group<\/span>/s", $content);
    }

    public function test_people_nav_does_not_show_active_fill_on_dashboard(): void
    {
        $response = $this->actingAs($this->user)->get('/en/dashboard');

        $response->assertStatus(200);
        $content = $response->getContent();
        // The group icon should NOT have FILL 1 when on dashboard (not people page)
        // Count FILL 1 occurrences — should be exactly 1 for the dashboard icon only
        $fillCount = substr_count($content, "font-variation-settings: 'FILL' 1");
        // Dashboard icon gets FILL 1; people icon should not
        $groupFillCount = substr_count($content, "FILL&#039; 1&quot;>group</span>");
        $this->assertEquals(0, $groupFillCount, 'People nav icon should not be filled on dashboard page');
    }

    public function test_i18n_key_exists_for_nav_people(): void
    {
        $this->assertNotNull(__('profile.nav_people'));
        $this->assertNotEquals('profile.nav_people', __('profile.nav_people'));
    }

    public function test_i18n_key_exists_for_content_people(): void
    {
        $this->assertNotNull(__('profile.content_people'));
        $this->assertNotEquals('profile.content_people', __('profile.content_people'));
    }

    public function test_german_translation_exists_for_people(): void
    {
        app()->setLocale('de');
        $this->assertEquals('Leute', __('profile.nav_people'));
        $this->assertEquals('Leute', __('profile.content_people'));
    }

    // ── GM Directory Nav ──────────────────────────────────────────

    public function test_gm_directory_link_appears_in_authenticated_sidebar(): void
    {
        $response = $this->actingAs($this->user)->get('/en/dashboard');

        $response->assertStatus(200);
        $response->assertSee(route('gm.directory'));
        $response->assertSee(__('profile.nav_gm_directory'));
    }

    public function test_gm_directory_uses_school_icon(): void
    {
        $response = $this->actingAs($this->user)->get('/en/dashboard');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('>school</span>', $content);
    }

    public function test_gm_directory_i18n_keys_exist(): void
    {
        $this->assertNotNull(__('profile.nav_gm_directory'));
        $this->assertNotEquals('profile.nav_gm_directory', __('profile.nav_gm_directory'));
    }

    public function test_gm_directory_german_translation_exists(): void
    {
        app()->setLocale('de');
        $this->assertNotEquals('profile.nav_gm_directory', __('profile.nav_gm_directory'));
    }

    // ── GM Workspace Nav ──────────────────────────────────────────

    public function test_gm_workspace_link_not_visible_to_non_gm(): void
    {
        $response = $this->actingAs($this->user)->get('/en/dashboard');

        $response->assertStatus(200);
        $response->assertDontSee(route('gm.workspace'));
        $response->assertDontSee(__('profile.nav_gm_workspace'));
    }

    public function test_gm_workspace_link_visible_to_gm(): void
    {
        $gm = $this->createGmUser();

        $response = $this->actingAs($gm)->get('/en/dashboard');

        $response->assertStatus(200);
        $response->assertSee(route('gm.workspace'));
        $response->assertSee(__('profile.nav_gm_workspace'));
    }

    public function test_gm_workspace_uses_casino_icon(): void
    {
        $gm = $this->createGmUser();

        $response = $this->actingAs($gm)->get('/en/dashboard');

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('>casino</span>', $content);
    }

    public function test_gm_workspace_i18n_keys_exist(): void
    {
        $this->assertNotNull(__('profile.nav_gm_workspace'));
        $this->assertNotEquals('profile.nav_gm_workspace', __('profile.nav_gm_workspace'));
    }

    public function test_gm_workspace_german_translation_exists(): void
    {
        app()->setLocale('de');
        $this->assertNotEquals('profile.nav_gm_workspace', __('profile.nav_gm_workspace'));
    }

    // ── Dashboard GM Card ─────────────────────────────────────────

    public function test_dashboard_gm_workspace_card_not_visible_to_non_gm(): void
    {
        $response = $this->actingAs($this->user)->get('/en/dashboard');

        $response->assertStatus(200);
        $response->assertDontSee(__('profile.dashboard_card_gm_workspace'));
    }

    public function test_dashboard_gm_workspace_card_visible_to_gm(): void
    {
        $gm = $this->createGmUser();

        $response = $this->actingAs($gm)->get('/en/dashboard');

        $response->assertStatus(200);
        $response->assertSee(__('profile.dashboard_card_gm_workspace'));
        $response->assertSee(__('profile.dashboard_card_gm_workspace_desc'));
    }

    public function test_dashboard_gm_workspace_card_links_to_workspace(): void
    {
        $gm = $this->createGmUser();

        $response = $this->actingAs($gm)->get('/en/dashboard');

        $response->assertStatus(200);
        $response->assertSee(route('gm.workspace'));
    }

    public function test_dashboard_gm_card_i18n_keys_exist(): void
    {
        $this->assertNotNull(__('profile.dashboard_card_gm_workspace'));
        $this->assertNotEquals('profile.dashboard_card_gm_workspace', __('profile.dashboard_card_gm_workspace'));
        $this->assertNotNull(__('profile.dashboard_card_gm_workspace_desc'));
        $this->assertNotEquals('profile.dashboard_card_gm_workspace_desc', __('profile.dashboard_card_gm_workspace_desc'));
    }

    // ── Public Layout GM Directory ────────────────────────────────

    public function test_gm_directory_appears_in_public_nav(): void
    {
        $response = $this->get('/en/gms');

        $response->assertStatus(200);
        $response->assertSee(__('profile.nav_gm_directory'));
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function createGmUser(): User
    {
        \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);

        $user->assignRole('Game Master');

        \App\Models\GMProfile::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        return $user;
    }
}
