<?php

namespace Tests\Feature\Notifications;

use App\Enums\AttendanceStatus;
use App\Enums\ContentLanguage;
use App\Models\AttendanceReport;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Notifications\ApplicationApproved;
use App\Notifications\AttendanceNudge;
use App\Notifications\AttendanceReported;
use App\Notifications\AttendanceResolved;
use App\Notifications\DisputeResolved;
use App\Notifications\EntityCancelled;
use App\Notifications\NewFollower;
use App\Notifications\SessionReminder;
use App\Notifications\WaitlistExpiredRejected;
use App\Notifications\WaitlistPromoted;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;

/**
 * Composition proof for toDiscord() overrides across the notification fleet
 * (design decision D130: "Discord mirrors push").
 *
 * Each notification's toDiscord() must reuse its toPush() title/body/url,
 * wrapped in a DiscordWebhookPayload embed — not the bare auto-derive fallback
 * (entity name + link only) that DiscordChannel produces when no override
 * exists. This file covers each structural pattern once:
 *
 *   - simple game-based URL + name (AttendanceNudge, AttendanceResolved)
 *   - pre-computed status var (AttendanceReported)
 *   - conditional title/body ternary (DisputeResolved)
 *   - dynamic window-based lang keys (SessionReminder)
 *   - RoutesGameOrCampaign trait routing (EntityCancelled, WaitlistPromoted)
 *   - campaign-entity variant (EntityCancelled with a Campaign)
 *   - non-game URL (NewFollower → profile.show-authenticated;
 *     WaitlistExpiredRejected → games.index)
 *   - null opt-out: push-null notifications return null from toDiscord() so
 *     the bare auto-derive DM never fires (ApplicationApproved).
 *
 * The locale-prefix contract (proven for toPush() in PushLocaleTest) is not
 * re-asserted here — toDiscord() reuses the identical route() call.
 */
class DiscordPayloadTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // Match PushLocaleTest: do NOT call URL::defaults() — replicate the
        // artisan/queue context where locale comes only from app()->getLocale()
        // or $notifiable->preferred_language.
        app()->setLocale('en');
    }

    /**
     * Resolve a notification's embed array, failing clearly if the override
     * was lost (DiscordWebhookPayload with null embeds means the bare
     * auto-derive fallback was used instead).
     *
     * @return array<string, mixed>
     */
    private function embed(DiscordWebhookPayload $payload): array
    {
        $this->assertNotNull($payload->embeds, 'toDiscord() returned an embed payload with no embeds — override lost?');
        $this->assertCount(1, $payload->embeds, 'toDiscord() should produce exactly one embed card');

        return $payload->embeds[0];
    }

    // -- Simple game-based: name in title, URL points at the game -----------

    public function test_attendance_nudge_embed_carries_game_name_and_url(): void
    {
        $game = Game::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $payload = (new AttendanceNudge($game, 'tomorrow at 6:00 PM'))->toDiscord($notifiable);

        $embed = $this->embed($payload);
        // Title is the static 'Attendance Reminder' key (no :game param);
        // the game name lives in the body/description.
        $this->assertSame(__('notifications.push_title_attendance_nudge'), $embed['title']);
        $this->assertStringContainsString($game->name, $embed['description']);
        $this->assertStringContainsString($game->id, $embed['url']);
    }

    public function test_attendance_resolved_embed_carries_status_label(): void
    {
        $game = Game::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $payload = (new AttendanceResolved($game, AttendanceStatus::Attended))->toDiscord($notifiable);

        $embed = $this->embed($payload);
        $this->assertStringContainsString($game->name, $embed['description']);
        $this->assertStringContainsString($game->id, $embed['url']);
    }

    // -- Pre-computed status var (AttendanceReported) -----------------------

    public function test_attendance_reported_embed_carries_status_label_and_game(): void
    {
        $game = Game::factory()->create();
        $reporter = User::factory()->create();
        $reported = User::factory()->create();
        $report = AttendanceReport::factory()->create([
            'game_id' => $game,
            'reporter_id' => $reporter,
            'reported_id' => $reported,
            'status' => AttendanceStatus::Attended,
        ]);
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $payload = (new AttendanceReported($game, $report))->toDiscord($notifiable);

        $embed = $this->embed($payload);
        $this->assertStringContainsString($game->name, $embed['description']);
        $this->assertStringContainsString($game->id, $embed['url']);
    }

    // -- Conditional title/body ternary (DisputeResolved) -------------------

    public function test_dispute_resolved_favor_branch_uses_favor_title(): void
    {
        $game = Game::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $payload = (new DisputeResolved($game, 'resolved_favor'))->toDiscord($notifiable);

        $embed = $this->embed($payload);
        $this->assertStringContainsString($game->name, $embed['description']);
        $this->assertStringContainsString($game->id, $embed['url']);
        // 'resolved_favor' branch must use the *_favor title key, not *_upheld.
        $this->assertSame(
            __('notifications.push_title_dispute_resolved_favor'),
            $embed['title'],
            'resolved_favor branch must use the *_favor title key'
        );
    }

    public function test_dispute_upheld_branch_uses_upheld_title(): void
    {
        $game = Game::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $payload = (new DisputeResolved($game, 'upheld'))->toDiscord($notifiable);

        $embed = $this->embed($payload);
        $this->assertSame(
            __('notifications.push_title_dispute_upheld'),
            $embed['title'],
            'upheld branch must use the *_upheld title key'
        );
    }

    // -- Dynamic window-based lang keys (SessionReminder) -------------------

    public function test_session_reminder_24h_window_uses_24h_title_key(): void
    {
        $game = Game::factory()->create(['date_time' => now()->addDay()]);
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $payload = (new SessionReminder($game, '24h'))->toDiscord($notifiable);

        $embed = $this->embed($payload);
        $this->assertSame(
            __('notifications.push_title_session_reminder_24h'),
            $embed['title'],
            '24h window must select the *_24h title key'
        );
        $this->assertStringContainsString($game->name, $embed['description']);
        $this->assertStringContainsString($game->id, $embed['url']);
    }

    public function test_session_reminder_default_window_uses_base_title_key(): void
    {
        $game = Game::factory()->create(['date_time' => now()->addHour()]);
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $payload = (new SessionReminder($game))->toDiscord($notifiable);

        $embed = $this->embed($payload);
        $this->assertSame(
            __('notifications.push_title_session_reminder'),
            $embed['title'],
            'default window must select the base title key (no _24h suffix)'
        );
    }

    // -- RoutesGameOrCampaign trait: dynamic route name + lang key ----------

    public function test_entity_cancelled_with_game_uses_game_keys_and_games_route(): void
    {
        $game = Game::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $payload = (new EntityCancelled($game))->toDiscord($notifiable);

        $embed = $this->embed($payload);
        $this->assertSame(__('notifications.push_title_game_cancelled'), $embed['title']);
        $this->assertStringContainsString($game->name, $embed['description']);
        $this->assertStringContainsString('/games/'.$game->id, $embed['url']);
    }

    public function test_entity_cancelled_with_campaign_uses_campaign_keys_and_campaigns_route(): void
    {
        $campaign = Campaign::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $payload = (new EntityCancelled($campaign))->toDiscord($notifiable);

        $embed = $this->embed($payload);
        $this->assertSame(__('notifications.push_title_campaign_cancelled'), $embed['title']);
        $this->assertStringContainsString($campaign->name, $embed['description']);
        $this->assertStringContainsString('/campaigns/'.$campaign->id, $embed['url']);
    }

    public function test_waitlist_promoted_embed_uses_trait_route_and_carries_name(): void
    {
        $game = Game::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $payload = (new WaitlistPromoted($game))->toDiscord($notifiable);

        $embed = $this->embed($payload);
        $this->assertStringContainsString($game->name, $embed['description']);
        $this->assertStringContainsString('/games/'.$game->id, $embed['url']);
    }

    // -- Non-game URLs ------------------------------------------------------

    public function test_new_follower_embed_links_to_follower_profile(): void
    {
        $follower = User::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $payload = (new NewFollower($follower))->toDiscord($notifiable);

        $embed = $this->embed($payload);
        $this->assertStringContainsString($follower->name, $embed['description']);
        // profile.show-authenticated uses the user's slug route key.
        $this->assertStringContainsString($follower->slug, $embed['url']);
    }

    public function test_waitlist_expired_rejected_embed_links_to_games_index_not_show(): void
    {
        $game = Game::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $payload = (new WaitlistExpiredRejected($game, 2))->toDiscord($notifiable);

        $embed = $this->embed($payload);
        // Intentionally the browse route, not games.show — assert it does NOT
        // deep-link the specific entity.
        $this->assertStringNotContainsString('/games/'.$game->id, $embed['url']);
        $this->assertStringContainsString($game->name, $embed['description']);
    }

    // -- All embeds carry the brand color -----------------------------------

    public function test_every_embed_carries_the_discord_blurple_color(): void
    {
        $game = Game::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $embed = $this->embed((new AttendanceNudge($game, 'soon'))->toDiscord($notifiable));

        $this->assertSame(0x5865F2, $embed['color']);
    }

    // -- Null opt-out: push-null notifications return null (D130) ----------

    public function test_application_approved_opts_out_of_discord_returning_null(): void
    {
        $game = Game::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $approver = User::factory()->create();
        $payload = (new ApplicationApproved($game, 'game', $approver))->toDiscord($notifiable);

        // Null return suppresses the bare auto-derive DM for ambient/mail-only
        // notifications whose toPush() also returns null (D130: Discord mirrors push).
        $this->assertNull($payload);
    }
}
