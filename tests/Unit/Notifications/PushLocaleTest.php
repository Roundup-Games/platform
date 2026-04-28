<?php

namespace Tests\Unit\Notifications;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Notifications\CampaignCancelled;
use App\Notifications\CampaignInvitation;
use App\Notifications\GameCancelled;
use App\Notifications\GameInvitation;
use App\Notifications\NewFollower;
use App\Notifications\SessionReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\URL;

/**
 * Verify that all toPush() methods generate valid locale-prefixed URLs
 * even when URL::defaults() has NOT been set (artisan command context).
 *
 * The existing NotificationPushPayloadTest masks this bug because its
 * Pest beforeEach sets URL::defaults(['locale' => 'en']).
 */
class PushLocaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Intentionally do NOT call URL::defaults() — this simulates
     * artisan command context where the bug was reported.
     *
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Deliberately NOT calling URL::defaults(['locale' => 'en'])
        // to reproduce the artisan command context.
        app()->setLocale('en');
    }

    // -- SessionReminder ---------------------------------------------------

    public function test_session_reminder_url_contains_locale(): void
    {
        $game = Game::factory()->create(['date_time' => now()->addHour()]);
        $notifiable = User::factory()->create();

        $payload = (new SessionReminder($game))->toPush($notifiable);

        $this->assertStringContainsString('/en/games/', $payload->url);
    }

    // -- GameInvitation ----------------------------------------------------

    public function test_game_invitation_url_contains_locale(): void
    {
        $game = Game::factory()->create();
        $inviter = User::factory()->create();
        $notifiable = User::factory()->create();

        $payload = (new GameInvitation($game, $inviter))->toPush($notifiable);

        $this->assertStringContainsString('/en/games/', $payload->url);
    }

    // -- CampaignInvitation ------------------------------------------------

    public function test_campaign_invitation_url_contains_locale(): void
    {
        $campaign = Campaign::factory()->create();
        $inviter = User::factory()->create();
        $notifiable = User::factory()->create();

        $payload = (new CampaignInvitation($campaign, $inviter))->toPush($notifiable);

        $this->assertStringContainsString('/en/campaigns/', $payload->url);
    }

    // -- GameCancelled -----------------------------------------------------

    public function test_game_cancelled_url_contains_locale(): void
    {
        $game = Game::factory()->create();
        $notifiable = User::factory()->create();

        $payload = (new GameCancelled($game))->toPush($notifiable);

        $this->assertStringContainsString('/en/games/', $payload->url);
    }

    // -- CampaignCancelled -------------------------------------------------

    public function test_campaign_cancelled_url_contains_locale(): void
    {
        $campaign = Campaign::factory()->create();
        $notifiable = User::factory()->create();

        $payload = (new CampaignCancelled($campaign))->toPush($notifiable);

        $this->assertStringContainsString('/en/campaigns/', $payload->url);
    }

    // -- NewFollower (already fixed — regression guard) --------------------

    public function test_new_follower_url_contains_locale(): void
    {
        $follower = User::factory()->create();
        $notifiable = User::factory()->create();

        $payload = (new NewFollower($follower))->toPush($notifiable);

        $this->assertStringContainsString('/en/u/', $payload->url);
    }

    // -- German locale variant --------------------------------------------

    public function test_game_invitation_url_respects_german_locale(): void
    {
        app()->setLocale('de');
        $game = Game::factory()->create();
        $inviter = User::factory()->create();
        $notifiable = User::factory()->create();

        $payload = (new GameInvitation($game, $inviter))->toPush($notifiable);

        $this->assertStringContainsString('/de/games/', $payload->url);
    }
}
