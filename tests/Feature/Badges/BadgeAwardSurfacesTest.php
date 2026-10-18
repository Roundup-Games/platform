<?php

use App\Dto\BadgeAwardResult;
use App\Enums\BadgeSource;
use App\Enums\NotificationCategory;
use App\Models\User;
use App\Notifications\BadgeAwardedNotification;
use App\Services\Badges\BadgeService;
use App\Services\Badges\Events\BadgeAwarded;
use App\Services\PostHogClient;
use App\Services\PostHogConsentChecker;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Helpers\TestablePostHogClient;

/**
 * Award-moment surfaces (M069 S02 T5): the BadgeAwarded / BadgeUpgraded
 * domain events reach BOTH surfaces — a BadgeAwardedNotification through
 * NotificationService (per-category channel prefs) and a PostHog
 * badge_awarded / badge_upgraded capture — while idempotent AlreadyHeld
 * re-awards and quiet lapses (spec §4) send and capture nothing.
 *
 * Awards go through the real BadgeService on synced definitions. The
 * listeners are queued; QUEUE_CONNECTION=sync runs them inline at event
 * dispatch, so Notification::fake() observes exactly what the pipeline
 * produced. PostHog is intercepted with the TestablePostHogClient
 * (consent granted via a checker mock, per the PostHogAnalyticsTest
 * precedent — inlined here because top-level helpers share the global
 * Pest namespace). NotificationService's own `notification.sent`
 * captures share the client, so assertions filter by event name.
 *
 * Copy assertions are wording-agnostic (K004): they compare against
 * trans() lookups, never literal lang values.
 */
beforeEach(function () {
    $this->artisan('badges:sync')->assertSuccessful();

    Notification::fake();

    Config::set('posthog.enabled', true);
    Config::set('posthog.api_key', 'phc_test_key');

    $this->posthogClient = new TestablePostHogClient;
    $this->app->instance(PostHogClient::class, $this->posthogClient);

    $checker = test()->mock(PostHogConsentChecker::class);
    $checker->shouldReceive('hasAnalyticsConsent')->andReturn(true);
    $this->app->instance(PostHogConsentChecker::class, $checker);

    $this->user = User::factory()->create();
});

it('notifies and captures when a badge is awarded', function () {
    $result = app(BadgeService::class)->award($this->user, 'supporting_member', 'year_1');

    expect($result)->toBe(BadgeAwardResult::Awarded);

    Notification::assertSentTo(
        $this->user,
        BadgeAwardedNotification::class,
        function ($notification) {
            return $notification->badgeKey === 'supporting_member'
                && $notification->tier === 'year_1'
                && $notification->upgraded === false;
        }
    );

    $event = collect($this->posthogClient->capturedCalls)
        ->first(fn ($call) => ($call['event'] ?? null) === 'badge_awarded');

    expect($event)->not->toBeNull()
        ->and($event['distinctId'])->toBe((string) $this->user->id)
        ->and($event['properties']['badge'])->toBe('supporting_member')
        ->and($event['properties']['tier'])->toBe('year_1')
        ->and($event['properties']['source'])->toBe('automatic');
});

it('notifies and captures again when a badge is upgraded', function () {
    $service = app(BadgeService::class);
    $service->award($this->user, 'supporting_member', 'year_1');

    $result = $service->award($this->user, 'supporting_member', 'year_2');

    expect($result)->toBe(BadgeAwardResult::Upgraded);

    Notification::assertSentTo(
        $this->user,
        BadgeAwardedNotification::class,
        function ($notification) {
            return $notification->tier === 'year_2'
                && $notification->upgraded === true;
        }
    );

    Notification::assertSentToTimes($this->user, BadgeAwardedNotification::class, 2);

    $event = collect($this->posthogClient->capturedCalls)
        ->first(fn ($call) => ($call['event'] ?? null) === 'badge_upgraded');

    expect($event)->not->toBeNull()
        ->and($event['properties']['badge'])->toBe('supporting_member')
        ->and($event['properties']['tier'])->toBe('year_2')
        ->and($event['properties']['source'])->toBe('automatic');
});

it('sends nothing when the same tier is awarded again (AlreadyHeld)', function () {
    $service = app(BadgeService::class);

    expect($service->award($this->user, 'supporting_member', 'year_1'))->toBe(BadgeAwardResult::Awarded)
        ->and($service->award($this->user, 'supporting_member', 'year_1'))->toBe(BadgeAwardResult::AlreadyHeld);

    // Idempotency extends to both surfaces: still exactly one notification
    // and one badge_awarded capture.
    Notification::assertSentToTimes($this->user, BadgeAwardedNotification::class, 1);

    $awards = collect($this->posthogClient->capturedCalls)
        ->filter(fn ($call) => ($call['event'] ?? null) === 'badge_awarded');

    expect($awards)->toHaveCount(1);
});

it('stays quiet on lapse: no notification, no capture (spec §4)', function () {
    $service = app(BadgeService::class);
    $service->award($this->user, 'supporting_member', 'year_1');

    expect($service->lapse($this->user, 'supporting_member', 'membership_ended'))->toBeTrue();

    // The lapse added nothing on either surface — still exactly the one
    // award-moment notification and capture from the original award.
    Notification::assertSentToTimes($this->user, BadgeAwardedNotification::class, 1);

    $badgeEvents = collect($this->posthogClient->capturedCalls)
        ->filter(fn ($call) => str_starts_with((string) ($call['event'] ?? ''), 'badge_'));

    expect($badgeEvents)->toHaveCount(1)
        ->and($badgeEvents->first()['event'])->toBe('badge_awarded');
});

it('skips quietly when the recipient no longer exists at delivery time', function () {
    // A well-formed uuid that matches no row — the deleted-between-dispatch-
    // and-delivery case (a malformed id cannot occur from BadgeService).
    event(new BadgeAwarded((string) Str::uuid(), 'supporting_member', 'year_1', BadgeSource::Automatic, null, null));

    Notification::assertNothingSent();

    expect(collect($this->posthogClient->capturedCalls)
        ->filter(fn ($call) => str_starts_with((string) ($call['event'] ?? ''), 'badge_')))
        ->toBeEmpty();
});

it('defaults the badge_awarded category to in-app + push, mail opt-in', function () {
    $defaults = NotificationCategory::defaultSettings()['badge_awarded'];

    expect($defaults)->toBe([
        'database' => true,
        'mail' => false,
        'push' => true,
        'discord' => true,
    ])
        ->and(NotificationCategory::BadgeAwarded->group())->toBe('achievements')
        ->and(NotificationCategory::grouped())->toHaveKey('achievements')
        ->and(NotificationCategory::grouped()['achievements']['options'])->toHaveKey('badge_awarded');
});

it('renders the display name and tier from the registry and badges lang files', function () {
    $award = new BadgeAwardedNotification('regulars_table', 'bronze', BadgeSource::Automatic);
    $data = $award->toDatabase($this->user);

    $name = trans('badges.content_badge_regulars_table_name');
    $bronze = trans('badges.content_badge_tier_bronze');

    expect($data['type'])->toBe('badge_awarded')
        ->and($data['badge_name'])->toBe("{$name} ({$bronze})")
        ->and($data['badge_key'])->toBe('regulars_table')
        ->and($data['tier'])->toBe('bronze');

    $upgrade = new BadgeAwardedNotification('regulars_table', 'silver', BadgeSource::Automatic, upgraded: true);
    $upgradeData = $upgrade->toDatabase($this->user);

    $silver = trans('badges.content_badge_tier_silver');

    expect($upgradeData['type'])->toBe('badge_upgraded')
        ->and($upgradeData['badge_name'])->toBe("{$name} ({$silver})");
});

it('renders the award copy in the recipient locale', function () {
    app()->setLocale('de');

    $notification = new BadgeAwardedNotification('supporting_member', 'year_1', BadgeSource::Automatic);
    $mail = $notification->toMail($this->user);

    $badge = trans('badges.content_badge_supporting_member_name', [], 'de')
        .' ('.trans('badges.content_badge_tier_year_1', [], 'de').')';

    expect($mail->subject)->toBe(__('notifications.subject_badge_awarded', ['badge' => $badge], 'de'))
        ->and($mail->introLines)->toContain(__('notifications.body_badge_awarded', ['badge' => $badge], 'de'));
});
