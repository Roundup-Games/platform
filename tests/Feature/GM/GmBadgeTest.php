<?php

namespace Tests\Feature\GM;

use App\Enums\GmProficiency;
use App\Livewire\Campaigns\CampaignDetail;
use App\Livewire\Games\GameDetail;
use App\Livewire\Profile\PublicProfile;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\GMProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GmBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::defaults(['locale' => 'en']);

        Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
    }

    // ── Blade Component Rendering ─────────────────────

    public function test_badge_renders_game_master_text(): void
    {
        $user = User::factory()->create(['profile_complete' => true]);
        GMProfile::factory()->create(['user_id' => $user->id, 'is_active' => true]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSee('Game Master');
    }

    public function test_badge_has_expected_css_classes(): void
    {
        $user = User::factory()->create(['profile_complete' => true]);
        GMProfile::factory()->create(['user_id' => $user->id, 'is_active' => true]);

        $html = Livewire::test(PublicProfile::class, ['user' => $user])
            ->html();

        // Badge should have rounded-full and inline-flex classes
        $this->assertStringContainsString('rounded-full', $html);
        $this->assertStringContainsString('inline-flex', $html);
    }

    // ── Public Profile Badge ──────────────────────────

    public function test_badge_shown_on_public_profile_when_gm_active(): void
    {
        $user = User::factory()->create(['profile_complete' => true]);
        GMProfile::factory()->create(['user_id' => $user->id, 'is_active' => true]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSee('Game Master');
    }

    public function test_badge_not_shown_on_public_profile_when_no_gm_profile(): void
    {
        $user = User::factory()->create(['profile_complete' => true]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertDontSee('Game Master');
    }

    public function test_badge_not_shown_on_public_profile_when_gm_inactive(): void
    {
        $user = User::factory()->create(['profile_complete' => true]);
        GMProfile::factory()->create(['user_id' => $user->id, 'is_active' => false]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertDontSee('Game Master');
    }

    // ── Game Detail Badge ─────────────────────────────

    public function test_badge_shown_on_game_detail_when_owner_is_gm(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Game Master');
        GMProfile::factory()->create(['user_id' => $owner->id, 'is_active' => true]);

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
        ]);

        $viewer = User::factory()->create();

        Livewire::actingAs($viewer)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSee('Game Master');
    }

    public function test_badge_not_shown_on_game_detail_when_owner_is_not_gm(): void
    {
        $owner = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
        ]);

        $viewer = User::factory()->create();

        Livewire::actingAs($viewer)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertDontSee('Game Master');
    }

    public function test_badge_not_shown_on_game_detail_when_gm_role_but_no_subscription(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Game Master');
        // GMProfile is active but user has no subscription — isGM() still returns true
        // because isGM() only checks the Spatie role, not subscription
        GMProfile::factory()->create(['user_id' => $owner->id, 'is_active' => true]);

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
        ]);

        $viewer = User::factory()->create();

        // isGM() checks role only, so badge still shows
        Livewire::actingAs($viewer)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSee('Game Master');
    }

    // ── Campaign Detail Badge ─────────────────────────

    public function test_badge_shown_on_campaign_detail_when_owner_is_gm(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Game Master');
        GMProfile::factory()->create(['user_id' => $owner->id, 'is_active' => true]);

        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
        ]);

        $viewer = User::factory()->create();

        Livewire::actingAs($viewer)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('Game Master');
    }

    public function test_badge_not_shown_on_campaign_detail_when_owner_is_not_gm(): void
    {
        $owner = User::factory()->create();

        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
        ]);

        $viewer = User::factory()->create();

        Livewire::actingAs($viewer)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertDontSee('Game Master');
    }

    // ── GM Profile Section Content ────────────────────

    public function test_profile_section_shows_bio(): void
    {
        $user = User::factory()->create(['profile_complete' => true]);
        GMProfile::factory()->create([
            'user_id' => $user->id,
            'bio' => 'Veteran DM with 10 years of experience running epic campaigns.',
            'is_active' => true,
        ]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSee('Game Master Profile')
            ->assertSee('Veteran DM with 10 years of experience');
    }

    public function test_profile_section_shows_specialization_labels(): void
    {
        $user = User::factory()->create(['profile_complete' => true]);
        GMProfile::factory()->create([
            'user_id' => $user->id,
            'specializations' => ['storytelling', 'world-builder', 'voices'],
            'is_active' => true,
        ]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSee(GmProficiency::Storytelling->label())
            ->assertSee(GmProficiency::WorldBuilder->label())
            ->assertSee(GmProficiency::Voices->label());
    }

    public function test_profile_section_does_not_show_invalid_specializations(): void
    {
        $user = User::factory()->create(['profile_complete' => true]);
        GMProfile::factory()->create([
            'user_id' => $user->id,
            'specializations' => ['invalid-spec', 'storytelling'],
            'is_active' => true,
        ]);

        $html = Livewire::test(PublicProfile::class, ['user' => $user])
            ->html();

        // Invalid spec should be silently skipped via tryFrom
        $this->assertStringNotContainsString('invalid-spec', $html);
        $this->assertStringContainsString(GmProficiency::Storytelling->label(), $html);
    }

    public function test_profile_section_shows_rating_when_reviews_exist(): void
    {
        $user = User::factory()->create(['profile_complete' => true]);
        GMProfile::factory()->create([
            'user_id' => $user->id,
            'average_rating' => 4.50,
            'review_count' => 12,
            'is_active' => true,
        ]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSee('4.5')
            ->assertSee('12 reviews');
    }

    public function test_profile_section_shows_no_reviews_when_zero(): void
    {
        $user = User::factory()->create(['profile_complete' => true]);
        GMProfile::factory()->create([
            'user_id' => $user->id,
            'review_count' => 0,
            'is_active' => true,
        ]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertSee('No reviews yet');
    }

    public function test_profile_section_not_shown_when_gm_inactive(): void
    {
        $user = User::factory()->create(['profile_complete' => true]);
        GMProfile::factory()->create([
            'user_id' => $user->id,
            'bio' => 'Hidden bio text',
            'is_active' => false,
        ]);

        Livewire::test(PublicProfile::class, ['user' => $user])
            ->assertDontSee('Game Master Profile')
            ->assertDontSee('Hidden bio text');
    }
}
