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
}
