<?php

namespace Tests\Feature\Notifications;

use App\Enums\ContentLanguage;
use App\Models\User;
use App\Notifications\BaseNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\HtmlString;
use Tests\Feature\Notifications\Fixtures\EnvelopeProbeNotification;

/**
 * Envelope contract for BaseNotification's shared helpers (M072/S04).
 *
 * The notification envelope — locale resolution, greeting, default actor,
 * the push payload wrap (platform icon), the Discord embed wrap (blurple),
 * and the signed unsubscribe line — is concentrated in the base so wording
 * and branding tweaks land in one file instead of 40+ subclasses. These
 * tests pin each helper's contract so the subclass conversions (T02/T03)
 * can rely on it, and keep the D247 guarantee that channel methods stay
 * per-class (the base must never define toDiscord/toPush itself).
 */
class NotificationEnvelopeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    // ── resolveLocale ─────────────────────────────────────────────────

    public function test_resolve_locale_uses_notifiable_preferred_language(): void
    {
        $notifiable = User::factory()->make(['preferred_language' => ContentLanguage::De]);

        $this->assertSame('de', (new EnvelopeProbeNotification)->probeLocale($notifiable));
    }

    public function test_resolve_locale_falls_back_to_app_locale_when_preferred_language_is_null(): void
    {
        $notifiable = User::factory()->make(['preferred_language' => null]);

        $this->assertSame('en', (new EnvelopeProbeNotification)->probeLocale($notifiable));
    }

    // ── greetingLine ──────────────────────────────────────────────────

    public function test_greeting_line_addresses_the_notifiable_by_name(): void
    {
        $notifiable = User::factory()->make(['name' => 'Mira']);

        $this->assertSame(
            __('common.field_hey_name', ['name' => 'Mira']),
            (new EnvelopeProbeNotification)->probeGreeting($notifiable),
        );
    }

    public function test_greeting_line_falls_back_to_email_when_name_is_null(): void
    {
        $notifiable = User::factory()->make([
            'name' => null,
            'email' => 'ghost@roundup.example',
        ]);

        $this->assertSame(
            __('common.field_hey_name', ['name' => 'ghost@roundup.example']),
            (new EnvelopeProbeNotification)->probeGreeting($notifiable),
        );
    }

    // ── default actor ─────────────────────────────────────────────────

    public function test_default_actor_is_null_for_system_notifications(): void
    {
        $this->assertNull((new EnvelopeProbeNotification)->getActor());
    }

    // ── pushPayload ───────────────────────────────────────────────────

    public function test_push_payload_wraps_the_shared_envelope(): void
    {
        $payload = (new EnvelopeProbeNotification)
            ->probePushPayload('Title', 'Body copy', 'https://roundup.test/game/1', 'game-1');

        $this->assertSame('Title', $payload->title);
        $this->assertSame('Body copy', $payload->body);
        $this->assertSame('https://roundup.test/game/1', $payload->url);
        $this->assertSame('game-1', $payload->tag);
        $this->assertSame(BaseNotification::PUSH_ICON, $payload->icon);

        $this->assertSame([
            'title' => 'Title',
            'body' => 'Body copy',
            'icon' => BaseNotification::PUSH_ICON,
            'url' => 'https://roundup.test/game/1',
            'tag' => 'game-1',
        ], $payload->toArray());
    }

    public function test_push_payload_without_tag_omits_the_tag_from_the_wire_array(): void
    {
        $payload = (new EnvelopeProbeNotification)
            ->probePushPayload('Title', 'Body copy', 'https://roundup.test/game/1');

        $this->assertNull($payload->tag);
        $this->assertArrayNotHasKey('tag', $payload->toArray());
    }

    // ── discordEmbed ──────────────────────────────────────────────────

    public function test_discord_embed_wraps_the_shared_blurple_envelope(): void
    {
        $payload = (new EnvelopeProbeNotification)
            ->probeDiscordEmbed('Title', 'https://roundup.test/game/1', 'Body copy');

        $this->assertCount(1, $payload->embeds);
        $this->assertSame([
            'title' => 'Title',
            'url' => 'https://roundup.test/game/1',
            'description' => 'Body copy',
            'color' => BaseNotification::DISCORD_EMBED_COLOR,
        ], $payload->embeds[0]);
        $this->assertSame(0x5865F2, $payload->embeds[0]['color']);
        $this->assertNull($payload->components);
    }

    public function test_discord_embed_accepts_a_semantic_accent_color_override(): void
    {
        // Event lifecycle embeds (green = positive, red = cancellation) keep
        // their semantic accent while the wrap itself stays in the base.
        $payload = (new EnvelopeProbeNotification)
            ->probeDiscordEmbed('Title', 'https://roundup.test/game/1', 'Body copy', 0xED4245);

        $this->assertSame(0xED4245, $payload->embeds[0]['color']);
    }

    // ── channel methods stay per-class (D247) ─────────────────────────

    public function test_base_notification_does_not_define_to_discord_or_to_push(): void
    {
        $this->assertFalse(
            method_exists(BaseNotification::class, 'toDiscord'),
            'D247: BaseNotification must not define toDiscord() — every subclass writes an explicit one (payload = DM, null = opt-out).',
        );

        $this->assertFalse(
            method_exists(BaseNotification::class, 'toPush'),
            'BaseNotification must not define toPush() — push intent is declared per subclass and auto-detected by supportedChannels().',
        );
    }

    // ── unsubscribe line (moved from HasUnsubscribeLink) ──────────────

    public function test_unsubscribe_line_is_inherited_from_the_base_and_uses_the_recipient_locale(): void
    {
        $notifiable = User::factory()->create(['preferred_language' => ContentLanguage::De]);

        $line = (new EnvelopeProbeNotification)->probeUnsubscribeLine($notifiable, 'participant_joined');

        $this->assertInstanceOf(HtmlString::class, $line);
        $this->assertStringContainsString('/de/notifications/unsubscribe/'.$notifiable->id.'/', (string) $line);
        $this->assertStringContainsString(__('notifications.email_unsubscribe'), (string) $line);
    }

    // ── fleet completeness (the T03 sweep, made permanent) ────────────

    /**
     * The envelope lives in the base — no class under app/Notifications may
     * restate its literal lines. The preferred_language locale-resolve line
     * is the canary named by the slice plan; the greeting key, the push icon
     * path, and raw PushPayload/DiscordWebhookPayload construction round out
     * the set. BaseNotification itself is the only legitimate holder.
     */
    public function test_no_class_outside_the_base_restates_the_literal_envelope(): void
    {
        $envelopeLines = [
            'preferred_language->value' => 'resolveLocale()',
            'common.field_hey_name' => 'greetingLine()',
            'pwa-192x192.png' => 'pushPayload()',
            'new PushPayload(' => 'pushPayload()',
            'DiscordWebhookPayload::embed(' => 'discordEmbed()',
        ];

        $violations = [];

        foreach (glob(app_path('Notifications').'/*.php') as $file) {
            if (basename($file, '.php') === 'BaseNotification') {
                continue;
            }

            $source = (string) file_get_contents($file);

            foreach ($envelopeLines as $needle => $helper) {
                if (str_contains($source, $needle)) {
                    $violations[] = basename($file).' restates '.substr($needle, 0, 40).' — use '.$helper.' from BaseNotification';
                }
            }
        }

        $this->assertSame([], $violations, sprintf(
            "M072/S04: the notification envelope is concentrated in BaseNotification — these files restate it literally:\n%s",
            implode("\n", $violations),
        ));
    }
}
