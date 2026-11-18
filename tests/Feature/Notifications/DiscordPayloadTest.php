<?php

namespace Tests\Feature\Notifications;

use App\Enums\AttendanceStatus;
use App\Enums\ContentLanguage;
use App\Models\AttendanceReport;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\Game;
use App\Models\User;
use App\Notifications\ApplicationApproved;
use App\Notifications\AttendanceNudge;
use App\Notifications\AttendanceReported;
use App\Notifications\AttendanceResolved;
use App\Notifications\DisputeResolved;
use App\Notifications\EntityCancelled;
use App\Notifications\EntityInvitation;
use App\Notifications\EventAnnouncementPublished;
use App\Notifications\EventCancelled;
use App\Notifications\NewFollower;
use App\Notifications\RecapPosted;
use App\Notifications\SessionReminder;
use App\Notifications\WaitlistExpiredRejected;
use App\Notifications\WaitlistPromoted;
use App\Services\Discord\DiscordPalette;
use App\Services\Discord\DiscordWebhookPayload;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

        $payload = (new AttendanceNudge($game, Carbon::parse(now()->addDays(3)->toDateString().' 18:00:00', 'UTC')))->toDiscord($notifiable);

        $embed = $this->embed($payload);
        // Title is the static 'Attendance Reminder' key (no :game param);
        // the game name lives in the body/description.
        $this->assertSame(__('notifications.category_attendance_nudge'), $embed['title']);
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
            __('notifications.category_dispute_resolved'),
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
        $this->assertSame(__('common.activity_type_game_canceled'), $embed['title']);
        $this->assertStringContainsString($game->name, $embed['description']);
        $this->assertStringContainsString('/games/'.$game->id, $embed['url']);
    }

    public function test_entity_cancelled_with_campaign_uses_campaign_keys_and_campaigns_route(): void
    {
        $campaign = Campaign::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $payload = (new EntityCancelled($campaign))->toDiscord($notifiable);

        $embed = $this->embed($payload);
        $this->assertSame(__('common.activity_type_campaign_canceled'), $embed['title']);
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

    // -- D280: emerald default + semantic status colors ---------------------

    /**
     * D280: the blurple default is gone. Every embed whose class declares no
     * semantic bucket resolves to the roundup emerald accent from the shared
     * palette (SessionReminder is the no-bucket representative).
     */
    public function test_every_embed_defaults_to_the_roundup_emerald_accent(): void
    {
        $game = Game::factory()->create(['date_time' => now()->addHour()]);
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $embed = $this->embed((new SessionReminder($game))->toDiscord($notifiable));

        $this->assertSame(DiscordPalette::EMERALD, $embed['color']);
    }

    /**
     * D280 semantic matrix: one representative class per bucket asserts its
     * color. Cancellation red, ambient grey (completed/informational),
     * action-needed amber. (EntityCompleted and the GameSystemRequest family
     * are null opt-outs (D130) — their overrides document the bucket for a
     * future DM, so they have no embed to assert here.)
     */
    public function test_semantic_status_colors_map_one_representative_per_bucket(): void
    {
        $game = Game::factory()->create();
        $author = User::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);
        $event = Event::factory()->create(['organizer_id' => $notifiable->id]);

        $cases = [
            'cancellation (EntityCancelled)' => [
                (new EntityCancelled($game))->toDiscord($notifiable),
                DiscordPalette::CANCELLATION,
            ],
            'cancellation (EventCancelled — event family, realigned to the palette red)' => [
                (new EventCancelled($event))->toDiscord($notifiable),
                DiscordPalette::CANCELLATION,
            ],
            'cancellation (WaitlistExpiredRejected — expiry family)' => [
                (new WaitlistExpiredRejected($game, 2))->toDiscord($notifiable),
                DiscordPalette::CANCELLATION,
            ],
            'positive event outcome (EventAnnouncementPublished — brand emerald, not Discord-brand green)' => [
                (new EventAnnouncementPublished(
                    EventAnnouncement::create([
                        'event_id' => $event->id,
                        'author_id' => $notifiable->id,
                        'title' => 'Probe announcement',
                        'content' => 'A rendered probe body.',
                        'is_published' => true,
                    ]),
                    $event,
                ))->toDiscord($notifiable),
                DiscordPalette::EMERALD,
            ],
            'ambient (RecapPosted)' => [
                (new RecapPosted($game, $author))->toDiscord($notifiable),
                DiscordPalette::AMBIENT,
            ],
            'action-needed (AttendanceNudge)' => [
                (new AttendanceNudge($game, Carbon::parse(now()->addDays(3)->toDateString().' 18:00:00', 'UTC')))->toDiscord($notifiable),
                DiscordPalette::ACTION_NEEDED,
            ],
        ];

        foreach ($cases as $label => [$payload, $expected]) {
            $embed = $this->embed($payload);
            $this->assertSame($expected, $embed['color'], $label.' must resolve to its D280 palette constant');
        }
    }

    // -- Structural mention guard (allowed_mentions parse:[] default) ------

    /**
     * D288 rider (M080/S04/T04): no notification passes an explicit
     * allowlist, so every DM payload's serialized wire shape must carry the
     * structural parse:[] default. Discord's absent-field behavior parses
     * every mention in content — the default makes user-controlled text
     * (game names, venue names) structurally unable to trigger pings.
     */
    public function test_discord_payloads_carry_the_allowed_mentions_parse_default(): void
    {
        $game = Game::factory()->create();
        $campaign = Campaign::factory()->create();
        $follower = User::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $payloads = [
            (new AttendanceNudge($game, Carbon::parse(now()->addDays(3)->toDateString().' 18:00:00', 'UTC')))->toDiscord($notifiable),
            (new EntityCancelled($campaign))->toDiscord($notifiable),
            (new NewFollower($follower))->toDiscord($notifiable),
        ];

        foreach ($payloads as $payload) {
            $this->assertSame(
                ['parse' => []],
                $payload->toArray()['allowed_mentions'],
                get_class($payload).' must serialize with the structural mention guard',
            );
        }
    }

    /**
     * Adversarial case: an entity name that literally contains @everyone
     * still renders as inert embed text — the parse:[] default means Discord
     * parses no mention out of it, so a hostile name can never ping the
     * guild's everyone-role from a bot DM.
     */
    public function test_entity_name_containing_everyone_mention_stays_inert(): void
    {
        $game = Game::factory()->create(['name' => ['en' => 'Ping @everyone and @here heist']]);
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $payload = (new AttendanceNudge($game, Carbon::parse(now()->addDays(3)->toDateString().' 18:00:00', 'UTC')))->toDiscord($notifiable);

        $body = $payload->toArray();
        // The hostile name renders verbatim as embed text (no sanitization —
        // the guard is Discord-side parsing suppression, not munging)...
        $this->assertStringContainsString('@everyone', $body['embeds'][0]['description']);
        // ...but the wire shape allows no mention parsing at all.
        $this->assertSame(['parse' => []], $body['allowed_mentions']);
        $this->assertArrayNotHasKey('users', $body['allowed_mentions']);
        $this->assertArrayNotHasKey('roles', $body['allowed_mentions']);
    }

    // -- S04/T02: invitation enrichment (cover thumbnail + host author) --

    /**
     * S04/T02: the invitation embed reads as personally hosted. With a
     * host-uploaded cover present, the embed carries the 'thumb' conversion
     * URL as an ABSOLUTE thumbnail (Discord rejects relative paths), and
     * the author block names the inviter with their public profile link.
     * The structural parse:[] mention guard must survive the enrichment.
     */
    public function test_invitation_embed_carries_absolute_cover_thumbnail_and_host_author(): void
    {
        Storage::fake('public');

        $game = Game::factory()->create();
        $game->addMedia(UploadedFile::fake()->image('cover.jpg', 800, 600))
            ->toMediaCollection('cover');
        $inviter = User::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $payload = (new EntityInvitation($game, $inviter))->toDiscord($notifiable);

        $embed = $this->embed($payload);
        // Thumbnail: the cover's 'thumb' conversion URL, absolutely prefixed
        // (local disks resolve relative /storage/... paths; Discord rejects
        // relative URLs, so the guard in EntityInvitation absolutizes them).
        $expectedThumb = $game->getFirstMedia('cover')->getUrl('thumb');
        if (! str_starts_with($expectedThumb, 'http')) {
            $expectedThumb = rtrim((string) config('app.url'), '/').$expectedThumb;
        }
        $this->assertSame($expectedThumb, $embed['thumbnail']['url']);
        $this->assertStringStartsWith('http', $embed['thumbnail']['url']);
        // Author: the host (inviter), deep-linked to their public profile.
        $this->assertSame($inviter->name, $embed['author']['name']);
        $this->assertSame(
            route('profile.public', ['locale' => 'en', 'user' => $inviter]),
            $embed['author']['url'],
        );
        // D288 structural mention guard still present on the enriched payload.
        $this->assertSame(['parse' => []], $payload->toArray()['allowed_mentions']);
    }

    /**
     * S04/T02 graceful skip: an entity without host-uploaded cover media
     * omits the thumbnail key entirely — never a broken or fabricated URL.
     * The author block is independent of media state and still renders.
     */
    public function test_invitation_embed_without_cover_media_omits_thumbnail_key(): void
    {
        $game = Game::factory()->create();
        $inviter = User::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $payload = (new EntityInvitation($game, $inviter))->toDiscord($notifiable);

        $embed = $this->embed($payload);
        $this->assertArrayNotHasKey('thumbnail', $embed);
        $this->assertSame($inviter->name, $embed['author']['name']);
    }

    /**
     * S04/T02 campaign variant: invitations for campaigns pull the thumbnail
     * from the campaign's own 'cover' collection (Campaign composes the same
     * ResolvesCoverImage trait), not from a game relationship.
     */
    public function test_campaign_invitation_embed_uses_campaign_cover_collection(): void
    {
        Storage::fake('public');

        $campaign = Campaign::factory()->create();
        $campaign->addMedia(UploadedFile::fake()->image('campaign-cover.png', 1200, 630))
            ->toMediaCollection('cover');
        $inviter = User::factory()->create();
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::En]);

        $embed = $this->embed((new EntityInvitation($campaign, $inviter))->toDiscord($notifiable));

        $expectedThumb = $campaign->getFirstMedia('cover')->getUrl('thumb');
        if (! str_starts_with($expectedThumb, 'http')) {
            $expectedThumb = rtrim((string) config('app.url'), '/').$expectedThumb;
        }
        $this->assertSame($expectedThumb, $embed['thumbnail']['url']);
        $this->assertStringContainsString('/campaigns/'.$campaign->id, $embed['url']);
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
