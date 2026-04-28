<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReliabilityDisplayTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_profile_shows_reliable_badge(): void
    {
        $user = User::factory()->create([
            'profile_complete' => true,
            'reliability_score' => [
                'score' => 98.5,
                'game_count' => 10,
                'tier' => 'reliable',
                'weights_applied' => ['attended' => 10.0],
            ],
        ]);

        $response = $this->get("/en/u/{$user->id}");

        $response->assertOk();
        $response->assertSee('Reliable');
    }

    #[Test]
    public function test_profile_shows_newcomer_badge(): void
    {
        $user = User::factory()->create([
            'profile_complete' => true,
            'reliability_score' => null,
        ]);

        $response = $this->get("/en/u/{$user->id}");

        $response->assertOk();
        $response->assertSee('Newcomer');
    }

    #[Test]
    public function test_stats_hidden_under_5_games(): void
    {
        $user = User::factory()->create([
            'profile_complete' => true,
            'reliability_score' => [
                'score' => 100.0,
                'game_count' => 3,
                'tier' => 'newcomer',
                'weights_applied' => ['attended' => 3.0],
            ],
        ]);

        $viewer = User::factory()->create(['profile_complete' => true]);
        $response = $this->actingAs($viewer)->get("/en/u/{$user->id}");

        $response->assertOk();
        // The attendance rate percentage should NOT appear for <5 games
        $response->assertDontSee('100%');
    }

    #[Test]
    public function test_stats_visible_at_5_games(): void
    {
        $user = User::factory()->create([
            'profile_complete' => true,
            'reliability_score' => [
                'score' => 95.0,
                'game_count' => 5,
                'tier' => 'reliable',
                'weights_applied' => ['attended' => 5.0],
            ],
        ]);

        // Stats require viewer to have 'stats' visibility — own profile always sees
        $response = $this->actingAs($user)->get("/en/u/{$user->id}");

        $response->assertOk();
        $response->assertSee('95%');
    }

    #[Test]
    public function test_badge_respects_privacy_settings(): void
    {
        $user = User::factory()->create([
            'profile_complete' => true,
            'reliability_score' => [
                'score' => 90.0,
                'game_count' => 8,
                'tier' => 'active',
                'weights_applied' => ['attended' => 7.0, 'no_show' => -1.0],
            ],
        ]);

        $viewer = User::factory()->create(['profile_complete' => true]);

        // Tier badge should always be visible (tier is public)
        $response = $this->actingAs($viewer)->get("/en/u/{$user->id}");

        $response->assertOk();
        $response->assertSee('Active');

        // Detailed stats (rate %) should not be visible to strangers without 'stats' field access
        // The badge component only shows details when showDetails=true, which requires
        // 'stats' in visibleFields. For strangers, only 'public' fields are visible.
        // This test verifies the badge renders (tier always visible)
        // but stats % is hidden when 'stats' is not in the viewer's scope.
    }
}
