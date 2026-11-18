<?php

namespace Tests\Feature\Notifications;

use App\Dto\PushPayload;
use App\Enums\NotificationCategory;
use App\Models\User;
use App\Notifications\BaseNotification;
use App\Notifications\RecapPosted;
use App\Notifications\SessionReminder;
use App\Notifications\WaitlistPromoted;
use App\Services\Notifications\PushDeliveryProfile;
use App\Services\Notifications\SignedReadMarker;
use Carbon\Carbon;
use InvalidArgumentException;
use Minishlink\WebPush\Notification;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

/*
|--------------------------------------------------------------------------
| PushPayload richness + PushDeliveryProfile matrix (M081/S05/T01)
|--------------------------------------------------------------------------
|
| Two contracts live here:
|
| 1. The rich push payload DTO (D286 rider): action buttons, event
|    timestamp, monochrome badge, and renotify — all optional, so every
|    pre-existing toPush() call site compiles and serializes untouched.
|    The renotify-requires-tag contract fires AT CONSTRUCTION (never in
|    toArray(): PushChannel json_encodes toArray() outside its try/flush,
|    so a render-time throw would detonate mid-delivery).
|
| 2. The per-category urgency/TTL map keyed on the wire vocabulary of
|    ResolvesNotificationCategory (toDatabase()['type'] values — including
|    dynamically composed entity types and defensive entries like 'digest').
|    Unknown categories must default to the ambient profile, never throw.
*/

describe('PushPayload wire shape', function () {
    it('serializes the legacy four-key shape when no optional fields are set', function () {
        $payload = new PushPayload('Title', 'Body', '/icons/pwa-192x192.png', '/games/1');

        // Exact equality pins byte-level backward compatibility: no stray keys.
        expect($payload->toArray())->toBe([
            'title' => 'Title',
            'body' => 'Body',
            'icon' => '/icons/pwa-192x192.png',
            'url' => '/games/1',
        ]);
    });

    it('keeps the legacy shape when only a tag is set', function () {
        $payload = new PushPayload('Title', 'Body', '/icons/pwa-192x192.png', '/games/1', 'waitlist-promoted-game-1');

        expect($payload->toArray())->toBe([
            'title' => 'Title',
            'body' => 'Body',
            'icon' => '/icons/pwa-192x192.png',
            'url' => '/games/1',
            'tag' => 'waitlist-promoted-game-1',
        ]);
    });

    it('carries action buttons, timestamp, badge and renotify onto the wire', function () {
        $payload = new PushPayload(
            title: 'Spot confirmed available',
            body: 'Confirm your spot at Dragons of the Week by Friday 18:00.',
            icon: '/icons/pwa-192x192.png',
            url: '/games/1',
            tag: 'waitlist-promoted-game-1',
            actions: [
                ['label' => 'Confirm spot', 'url' => 'https://roundup.test/games/1'],
                ['label' => 'View', 'url' => 'https://roundup.test/games/1', 'action' => 'view'],
            ],
            timestamp: 1762300800,
            badge: '/icons/push-badge.png',
            renotify: true,
        );

        expect($payload->toArray())->toBe([
            'title' => 'Spot confirmed available',
            'body' => 'Confirm your spot at Dragons of the Week by Friday 18:00.',
            'icon' => '/icons/pwa-192x192.png',
            'url' => '/games/1',
            'tag' => 'waitlist-promoted-game-1',
            'actions' => [
                ['label' => 'Confirm spot', 'url' => 'https://roundup.test/games/1'],
                ['label' => 'View', 'url' => 'https://roundup.test/games/1', 'action' => 'view'],
            ],
            'timestamp' => 1762300800,
            'badge' => '/icons/push-badge.png',
            'renotify' => true,
        ]);
    });

    it('omits renotify from the wire when false and skips an empty action list', function () {
        $payload = new PushPayload(
            title: 'Title',
            body: 'Body',
            icon: '/icons/pwa-192x192.png',
            url: '/games/1',
            tag: 'recap-posted-game-1',
            actions: [],
            timestamp: null,
            badge: null,
            renotify: false,
        );

        expect($payload->toArray())->toBe([
            'title' => 'Title',
            'body' => 'Body',
            'icon' => '/icons/pwa-192x192.png',
            'url' => '/games/1',
            'tag' => 'recap-posted-game-1',
        ]);
    });
});

describe('PushPayload renotify-requires-tag contract', function () {
    it('throws InvalidArgumentException at construction when renotify is set without a tag', function () {
        expect(fn () => new PushPayload(
            title: 'Title',
            body: 'Body',
            icon: '/icons/pwa-192x192.png',
            url: '/games/1',
            renotify: true,
        ))->toThrow(InvalidArgumentException::class);
    });

    it('throws InvalidArgumentException at construction when renotify is set with an empty-string tag', function () {
        expect(fn () => new PushPayload(
            title: 'Title',
            body: 'Body',
            icon: '/icons/pwa-192x192.png',
            url: '/games/1',
            tag: '',
            renotify: true,
        ))->toThrow(InvalidArgumentException::class);
    });

    it('constructs and serializes renotify alongside a non-empty tag', function () {
        $payload = new PushPayload(
            title: 'Title',
            body: 'Body',
            icon: '/icons/pwa-192x192.png',
            url: '/games/1',
            tag: 'session-reminder-session-1',
            renotify: true,
        );

        expect($payload->toArray())
            ->toBe([
                'title' => 'Title',
                'body' => 'Body',
                'icon' => '/icons/pwa-192x192.png',
                'url' => '/games/1',
                'tag' => 'session-reminder-session-1',
                'renotify' => true,
            ])
            // The guard must not reject a truthy-but-not-"1"-looking tag: '0'
            // is a non-empty string and a legal replacement key.
            ->and((new PushPayload('T', 'B', '/i', '/u', '0', renotify: true))->toArray()['tag'])->toBe('0');
    });
});

describe('PushDeliveryProfile urgency/TTL matrix', function () {
    it('marks deadline-critical categories high-urgency with the 12h TTL', function (string $category) {
        $profile = PushDeliveryProfile::forCategory($category);

        // 12h = the longest confirmation window the tier serves (a shorter
        // TTL would drop the push while a waitlist seat is still confirmable).
        expect($profile->urgency)->toBe(PushDeliveryProfile::URGENCY_HIGH)
            ->and($profile->ttl)->toBe(PushDeliveryProfile::HIGH_TTL_SECONDS)
            ->and($profile->ttl)->toBe(12 * 3600)
            ->and($profile->toWebPushOptions())->toBe(['urgency' => 'high', 'TTL' => PushDeliveryProfile::HIGH_TTL_SECONDS]);
    })->with([
        'waitlist_promoted',
        'session_reminder',
        // Entity invitations (composed "{$type}_invitation" wire values).
        'game_invitation',
        'campaign_invitation',
        // Cancellations (composed "{$type}_cancelled" wire values).
        'game_cancelled',
        'campaign_cancelled',
        // Event confirmations AND cancellations share the event_registration
        // wire type (M063/S03), so the pair resolves together — a cancelled
        // event a member holds a registration for is deadline-critical.
        'event_registration',
    ]);

    it('gives ambient categories normal urgency with the 24h TTL', function (string $category) {
        $profile = PushDeliveryProfile::forCategory($category);

        expect($profile->urgency)->toBe(PushDeliveryProfile::URGENCY_NORMAL)
            ->and($profile->ttl)->toBe(24 * 3600)
            ->and($profile->toWebPushOptions())->toBe(['urgency' => 'normal', 'TTL' => 24 * 3600]);
    })->with([
        // Defensive vocabulary entries from the wire, not just enum cases.
        'digest',
        'new_follower',
        'recap_posted',
        'bulletin_posted',
        'promoted_from_bench',
        'game_system_request_approved',
        'confirmation_expired',
    ]);

    it('defaults unknown categories and class-basename fallbacks to the ambient profile', function (string $category) {
        $profile = PushDeliveryProfile::forCategory($category);

        // New/future types must never be silently deadline-critical — and
        // never throw: resolution is total, mirroring ResolvesNotificationCategory.
        expect($profile->urgency)->toBe('normal')
            ->and($profile->ttl)->toBe(24 * 3600);
    })->with([
        'some_future_type',
        'WeeklyDigest', // class-basename fallback shape
        '',
    ]);

    it('resolves every NotificationCategory enum value without throwing', function () {
        foreach (NotificationCategory::values() as $value) {
            $profile = PushDeliveryProfile::forCategory($value);

            expect(in_array($profile->urgency, ['high', 'normal'], true))->toBeTrue()
                ->and($profile->ttl)->toBeGreaterThan(0);
        }
    });

    it('satisfies the library option contract — the urgency actually survives Notification::getOptions()', function () {
        // Minishlink v11's Notification::getOptions() merges ONLY the keys
        // 'TTL', 'urgency', 'topic', 'contentType' — anything else (e.g. the
        // RFC header spelling 'Urgency') is dead weight the merge drops,
        // and WebPush never emits the header. This pins the option keys
        // against the REAL library merge instead of the app's own array,
        // which is how the original 'Urgency' spelling shipped unnoticed.
        $b64url = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $webPush = new WebPush(['VAPID' => [
            'subject' => 'https://roundup.test',
            'publicKey' => $b64url(str_repeat("\x01", 65)),
            'privateKey' => $b64url(str_repeat("\x02", 32)),
        ]]);
        $defaults = $webPush->getDefaultOptions();

        $subscription = Subscription::create([
            'endpoint' => 'https://push.example.test/endpoint',
            'keys' => [
                'p256dh' => $b64url(str_repeat("\x03", 65)),
                'auth' => $b64url(str_repeat("\x04", 16)),
            ],
        ]);

        foreach (['waitlist_promoted' => 'high', 'recap_posted' => 'normal'] as $category => $urgency) {
            $notification = new Notification(
                $subscription,
                null,
                PushDeliveryProfile::forCategory($category)->toWebPushOptions(),
                [],
            );

            $merged = $notification->getOptions($defaults);

            expect($merged['urgency'])->toBe($urgency, "{$category} urgency must survive the library merge")
                ->and($merged['TTL'])->toBe(PushDeliveryProfile::forCategory($category)->ttl);
        }
    });

    it('resolves identically in every locale — transport policy carries no localized labels', function () {
        $resolve = fn (): array => array_map(
            fn (string $category): string => PushDeliveryProfile::forCategory($category)->urgency.':'.PushDeliveryProfile::forCategory($category)->ttl,
            ['waitlist_promoted', 'session_reminder', 'digest', 'game_cancelled'],
        );

        app()->setLocale('en');
        $english = $resolve();

        app()->setLocale('de');
        $german = $resolve();

        expect($german)->toBe($english);
    });
});

/*
|--------------------------------------------------------------------------
| Deadline call-site riders (M081/S05/T02, D286)
|--------------------------------------------------------------------------
|
| The wire-level contract above only exists to serve real call sites:
| WaitlistPromoted and SessionReminder — the deadline-critical categories —
| carry renotify + their stable tag (a second push with the same tag
| replaces the previous notification and re-alerts), plus their natural
| richness (action button + event timestamp on the waitlist promotion,
| session-start timestamp on the reminder). Ambient call sites leave
| renotify off the wire entirely. Expected copy resolves via __() per K004
| — never literal lang strings.
*/

describe('deadline call-site riders (D286)', function () {
    beforeEach(function () {
        // Freeze the clock: the waitlist promotion's event timestamp is the
        // send moment, so a deterministic assertion needs a frozen now().
        $this->travelTo(Carbon::parse('2026-11-14 12:00:00'));
    });

    it('rides actions, event timestamp, tag and renotify on the waitlist-promotion push', function (string $locale) {
        app()->setLocale($locale);
        $game = $this->createGameWithOwner()['game'];
        $recipient = User::factory()->create();
        $deadline = Carbon::parse('2026-11-21 18:00:00');
        // The push url wraps the game route in the signed cross-channel
        // read marker (D288 item 1, M081/S08): tapping it marks the sibling
        // in-app row read before landing on the game page.
        $url = app(SignedReadMarker::class)->build(
            $recipient,
            $locale,
            WaitlistPromoted::class,
            $game->id,
            'entity_id',
            "/{$locale}/dashboard/games/{$game->id}",
        );

        $payload = (new WaitlistPromoted($game, $deadline))->toPush($recipient);

        expect($payload->toArray())->toBe([
            'title' => __('notifications.push_title_waitlist_promoted', [], $locale),
            'body' => __('notifications.push_body_waitlist_promoted', [
                'game' => $game->name,
                'deadline' => format_date($deadline, 'datetime', $recipient->timezone ?? community_timezone()),
            ], $locale),
            'icon' => BaseNotification::PUSH_ICON,
            'url' => $url,
            'tag' => "waitlist-promoted-game-{$game->id}",
            'actions' => [
                ['label' => __('notifications.push_action_confirm_spot', [], $locale), 'url' => $url],
            ],
            'timestamp' => now()->getTimestamp(),
            'badge' => BaseNotification::PUSH_BADGE,
            'renotify' => true,
        ]);
    })->with(['en', 'de']);

    it('renders a legacy string deadline queued by pre-M081 code (deploy-boundary tolerance)', function () {
        app()->setLocale('en');
        $game = $this->createGameWithOwner()['game'];
        $recipient = User::factory()->create();

        // Jobs serialized before M081 carry the deadline as the old
        // isoFormat('LLL') string (or the '' default) — a Carbon-only
        // property type makes those in-flight payloads fail unserialization
        // and lose the whole notification. The tolerant union parses the
        // string at render time; the empty string renders deadline-less.
        $legacyString = new WaitlistPromoted($game, 'Nov 21, 2026 6:00 PM');
        $parsed = Carbon::parse('Nov 21, 2026 6:00 PM');

        expect($legacyString->toPush($recipient)->toArray()['body'])->toBe(
            __('notifications.push_body_waitlist_promoted', [
                'game' => $game->name,
                'deadline' => format_date($parsed, 'datetime', $recipient->timezone ?? community_timezone()),
            ], 'en')
        );

        $legacyEmpty = new WaitlistPromoted($game, '');

        expect($legacyEmpty->toPush($recipient)->toArray()['body'])->toBe(
            __('notifications.push_body_waitlist_promoted', [
                'game' => $game->name,
                'deadline' => '',
            ], 'en')
        );
    });

    it('rides the session-start event timestamp, tag and renotify on the session reminder', function () {
        app()->setLocale('en');
        $startsAt = Carbon::parse('2026-11-15 19:00:00');
        $game = $this->createGameWithOwner(['date_time' => $startsAt])['game'];
        $recipient = User::factory()->create();

        $payload = (new SessionReminder($game, '1h'))->toPush($recipient);

        expect($payload->tag)->toBe("game-reminder-1h-{$game->id}")
            ->and($payload->renotify)->toBeTrue()
            // The event timestamp is the session start, not the send moment.
            ->and($payload->timestamp)->toBe($startsAt->getTimestamp())
            ->and($payload->actions)->toBeNull()
            // Deadline-critical pushes carry the monochrome tray badge (D286).
            ->and($payload->badge)->toBe(BaseNotification::PUSH_BADGE)
            ->and($payload->toArray())->toHaveKey('renotify')
            ->and($payload->toArray())->not->toHaveKey('actions');
    });

    it('leaves ambient pushes without renotify or richness on the wire', function () {
        app()->setLocale('en');
        $context = $this->createGameWithOwner();
        $recipient = User::factory()->create();

        $payload = (new RecapPosted($context['game'], $context['owner']))->toPush($recipient);

        expect($payload->renotify)->toBeFalse()
            ->and($payload->toArray())
            ->not->toHaveKey('renotify')
            ->not->toHaveKey('timestamp')
            ->not->toHaveKey('actions')
            // The ambient tag still rides: replacement without re-alert.
            ->and($payload->toArray())->toHaveKey('tag');
    });
});
