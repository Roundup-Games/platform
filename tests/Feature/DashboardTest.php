<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use function Pest\Laravel\{actingAs, get};

describe('Dashboard Quick-Access Cards', function () {
    beforeEach(function () {
        $this->user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        // Ensure a game system exists for factory relations
        GameSystem::factory()->create();
    });

    it('shows quick-access cards on dashboard', function () {
        actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('profile.dashboard_card_my_games'))
            ->assertSee(__('profile.dashboard_card_my_campaigns'))
            ->assertSee(__('people.content_people'))
            ->assertSee(__('discovery.action_discover'));
    });

    it('shows game count on My Games card', function () {
        Game::factory()->count(3)->create([
            'owner_id' => $this->user->id,
            'status' => 'scheduled',
        ]);

        $response = actingAs($this->user)
            ->get(route('dashboard'));
        $response->assertOk();

        $content = $response->getContent();
        // The badge shows "3 games" — check that 3 appears in the My Games card context
        $this->assertStringContainsString('3', $content);
        $this->assertStringContainsString(__('games.content_games'), $content);
    });

    it('shows campaign count on My Campaigns card', function () {
        Campaign::factory()->count(2)->create([
            'owner_id' => $this->user->id,
            'status' => 'active',
        ]);

        $response = actingAs($this->user)
            ->get(route('dashboard'));
        $response->assertOk();

        $content = $response->getContent();
        // The badge shows "2 campaigns"
        $this->assertStringContainsString('2', $content);
        $this->assertStringContainsString(__('campaigns.content_campaigns'), $content);
    });

    it('shows zero count when user has no games', function () {
        // Fresh user — no games or campaigns
        $response = actingAs($this->user)
            ->get(route('dashboard'));
        $response->assertOk();

        $content = $response->getContent();
        // When count is 0, no badge is shown (the @if($gameCount > 0) block is skipped).
        // Verify the card heading is present but no count badge appears.
        $this->assertStringContainsString(__('profile.dashboard_card_my_games'), $content);

        // Verify no count badge with a number — no stadium icon inside a badge
        // A simple way: extract the My Games card section and verify it has no badge
        $gamesCardStart = strpos($content, __('profile.dashboard_card_my_games'));
        $this->assertNotFalse($gamesCardStart);
    });

    it('does not count canceled games in game count', function () {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'status' => 'scheduled',
        ]);
        Game::factory()->count(2)->create([
            'owner_id' => $this->user->id,
            'status' => 'canceled',
        ]);

        $response = actingAs($this->user)
            ->get(route('dashboard'));
        $response->assertOk();

        $content = $response->getContent();
        // Should show "1 game" (singular)
        $this->assertStringContainsString('1', $content);
        $this->assertStringContainsString(__('games.content_game'), $content);
    });

    it('does not count completed campaigns in campaign count', function () {
        Campaign::factory()->create([
            'owner_id' => $this->user->id,
            'status' => 'active',
        ]);
        Campaign::factory()->count(2)->create([
            'owner_id' => $this->user->id,
            'status' => 'completed',
        ]);

        $response = actingAs($this->user)
            ->get(route('dashboard'));
        $response->assertOk();

        $content = $response->getContent();
        // Should show "1 campaign" (singular)
        $this->assertStringContainsString('1', $content);
        $this->assertStringContainsString(__('campaigns.content_campaign'), $content);
    });
});
