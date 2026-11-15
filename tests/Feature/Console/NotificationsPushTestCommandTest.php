<?php

namespace Tests\Feature\Console;

use App\Console\Dev\NotificationsPushTestCommand;
use App\Enums\NotificationCategory;
use App\Models\Game;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\BaseNotification;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Tests\Traits\CapturesLogRecords;

/*
|--------------------------------------------------------------------------
| notifications:push-test command (M080/S02/T02)
|--------------------------------------------------------------------------
|
| The dev-only real-device push harness. Guards (production refusal,
| category/user/locale validation, VAPID + subscription preconditions)
| and the two payload paths are pinned here:
|
|  - real notification classes where the category carries a push voice
|    (waitlist_promoted, new_follower): the transport mock asserts the
|    queueNotification attempt per subscription plus the payload shape —
|    icon pinned to the shared PUSH_ICON envelope, url/tag derived from
|    the real fixture entity;
|  - the shared-envelope fallback for NO_PUSH_VOICE categories
|    (game_system_request): still a sendable push, title = category label.
|
| The WebPush client is Mockery-mocked and bound as the container
| instance — the same boundary PushChannelTest fakes — while everything
| else (real User, real PushSubscription rows, the real command loop and
| real PushChannel send) runs for real.
*/

uses(CapturesLogRecords::class);

// ── Helpers ───────────────────────────────────────────────────────────────

function enableVapidConfig(): void
{
    config([
        'services.vapid.public_key' => 'test-public-key',
        'services.vapid.private_key' => 'test-private-key',
    ]);
}

/**
 * Bind a WebPush mock that captures every queueNotification attempt
 * (subscription + payload JSON) and reports an empty flush batch.
 *
 * @param  list<array{0: Subscription, 1: string}>  $captured
 */
function bindWebPushCapture(array &$captured): void
{
    $webPush = \Mockery::mock(WebPush::class);
    $webPush->shouldReceive('queueNotification')->andReturnUsing(
        function (Subscription $subscription, string $payloadJson) use (&$captured, $webPush): WebPush {
            $captured[] = [$subscription, $payloadJson];

            return $webPush;
        }
    );
    $webPush->shouldReceive('flush')->andReturn([]);

    app()->instance(WebPush::class, $webPush);
}

/**
 * Decode the payload JSON of the first (and expected only) captured send.
 *
 * @param  list<array{0: Subscription, 1: string}>  $captured
 * @return array<string, mixed>
 */
function capturedPayload(array $captured): array
{
    expect($captured)->not->toBeEmpty();

    $decoded = json_decode($captured[0][1], true);

    expect($decoded)->toBeArray();

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

// ── Guards ────────────────────────────────────────────────────────────────

it('refuses to run in production (defense-in-depth under the registration gate)', function () {
    // app()->environment() resolves the container's 'env' binding, not
    // live config('app.env') — rebind it like DemoEnvironmentGuardTest.
    app()->instance('env', 'production');

    $this->artisan('notifications:push-test', ['category' => 'waitlist_promoted', '--user' => 'dev@roundup.games'])
        ->expectsOutputToContain('disabled in production')
        ->assertFailed();
});

it('rejects an unknown category and lists the valid slugs', function () {
    $user = User::factory()->create();

    $this->artisan('notifications:push-test', ['category' => 'not_a_category', '--user' => $user->email])
        ->expectsOutputToContain('Unknown category [not_a_category]')
        ->expectsOutputToContain('waitlist_promoted')
        ->assertFailed();
});

it('requires the --user option', function () {
    $this->artisan('notifications:push-test', ['category' => 'waitlist_promoted'])
        ->expectsOutputToContain('--user option is required')
        ->assertFailed();
});

it('rejects an unknown --user email', function () {
    $this->artisan('notifications:push-test', ['category' => 'waitlist_promoted', '--user' => 'ghost@roundup.games'])
        ->expectsOutputToContain('No user found with email <ghost@roundup.games>')
        ->assertFailed();
});

it('rejects an unsupported locale', function () {
    $user = User::factory()->create();

    $this->artisan('notifications:push-test', ['category' => 'new_follower', '--user' => $user->email, '--locale' => 'fr'])
        ->expectsOutputToContain('Unsupported locale [fr]')
        ->assertFailed();
});

it('reports and fails when VAPID keys are unconfigured', function () {
    config(['services.vapid.public_key' => null, 'services.vapid.private_key' => null]);

    $user = User::factory()->create();
    PushSubscription::factory()->create(['user_id' => $user]);
    $captured = [];
    bindWebPushCapture($captured);

    $this->artisan('notifications:push-test', ['category' => 'waitlist_promoted', '--user' => $user->email])
        ->expectsOutputToContain('VAPID keys are not configured')
        ->assertFailed();

    // The payload was still rendered for review — but nothing was sent.
    expect($captured)->toBeEmpty();
});

it('reports and fails when the account has no push subscriptions', function () {
    enableVapidConfig();

    $user = User::factory()->create();
    $captured = [];
    bindWebPushCapture($captured);

    $this->artisan('notifications:push-test', ['category' => 'waitlist_promoted', '--user' => $user->email])
        ->expectsOutputToContain('has no push subscriptions')
        ->assertFailed();

    expect($captured)->toBeEmpty();
});

// ── Happy path: real notification payloads ────────────────────────────────

it('delivers the real WaitlistPromoted payload to the named account subscriptions', function () {
    $user = User::factory()->create();
    $subscription = PushSubscription::factory()->create(['user_id' => $user]);
    enableVapidConfig();
    $captured = [];
    bindWebPushCapture($captured);
    $this->captureLogRecords();

    // Output expectations stick to single-line info writes (the artisan
    // test runner's substring matcher reliably catches those); the printed
    // payload JSON is asserted through the captured transport send below.
    $this->artisan('notifications:push-test', ['category' => 'waitlist_promoted', '--user' => $user->email])
        ->expectsOutputToContain('Category: waitlist_promoted')
        ->expectsOutputToContain('Notification: App\Notifications\WaitlistPromoted')
        ->assertSuccessful();

    // One queueNotification attempt per subscription, targeting exactly
    // the account's subscription endpoint.
    expect($captured)->toHaveCount(1)
        ->and($captured[0][0]->getEndpoint())->toBe($subscription->endpoint);

    $payload = capturedPayload($captured);
    expect($payload['title'])->toBe(__('notifications.push_title_waitlist_promoted'))
        ->and($payload['body'])->toContain('Push Test Game')
        ->and($payload['icon'])->toBe(BaseNotification::PUSH_ICON)
        ->and($payload['url'])->toContain('/dashboard/games/')
        ->and($payload['tag'])->toContain('waitlist-promoted-game-');

    // The real-notification path built its fixture entity.
    expect(Game::query()->where('name->en', 'Push Test Game')->exists())->toBeTrue();

    // Structured log line — the machine record of the test push.
    $record = $this->logRecord('push.test');
    expect($record)->not->toBeNull()
        ->and($record['context']['category'])->toBe('waitlist_promoted')
        ->and($record['context']['user_id'])->toBe($user->id)
        ->and($record['context']['fallback_envelope'])->toBeFalse()
        ->and($record['context']['payload']['icon'])->toBe(BaseNotification::PUSH_ICON);
});

it('delivers the real NewFollower payload in the de locale', function () {
    $user = User::factory()->create();
    PushSubscription::factory()->create(['user_id' => $user]);
    enableVapidConfig();
    $captured = [];
    bindWebPushCapture($captured);

    $this->artisan('notifications:push-test', [
        'category' => 'new_follower',
        '--user' => $user->email,
        '--locale' => 'de',
    ])->assertSuccessful();

    $payload = capturedPayload($captured);
    expect($payload['title'])->toBe(__('notifications.category_new_follower', [], 'de'))
        ->and($payload['body'])->toContain(__('notifications.subject_new_follower', ['follower' => 'Push Test Actor'], 'de'))
        ->and($payload['icon'])->toBe(BaseNotification::PUSH_ICON)
        ->and($payload['url'])->toContain('/de/')
        ->and($payload['tag'])->toContain('new-follower-');
});

// ── Happy path: fallback envelope for no-push-voice categories ────────────

it('delivers the shared-envelope fallback for a category without a push voice', function () {
    $user = User::factory()->create();
    PushSubscription::factory()->create(['user_id' => $user]);
    enableVapidConfig();
    $captured = [];
    bindWebPushCapture($captured);
    $this->captureLogRecords();

    $this->artisan('notifications:push-test', ['category' => 'game_system_request', '--user' => $user->email])
        ->expectsOutputToContain('fallback envelope')
        ->assertSuccessful();

    expect($captured)->toHaveCount(1);

    $payload = capturedPayload($captured);
    expect($payload['title'])->toBe(__('notifications.category_game_system_request'))
        ->and($payload['body'])->toContain('game_system_request')
        ->and($payload['body'])->toContain('D130')
        ->and($payload['icon'])->toBe(BaseNotification::PUSH_ICON)
        ->and($payload['tag'])->toBe('push-test-game_system_request');

    $record = $this->logRecord('push.test');
    expect($record)->not->toBeNull()
        ->and($record['context']['category'])->toBe('game_system_request')
        ->and($record['context']['fallback_envelope'])->toBeTrue();
});

// ── Coverage hygiene ──────────────────────────────────────────────────────

it('maps every NotificationCategory to a sendable push payload', function () {
    $command = app(NotificationsPushTestCommand::class);

    $withoutVoice = [];
    foreach (NotificationCategory::cases() as $category) {
        $notification = $command->notificationFor($category);

        if ($notification === null) {
            $withoutVoice[] = $category->value;
            expect(array_key_exists($category->value, NotificationsPushTestCommand::NO_PUSH_VOICE))
                ->toBeTrue("[{$category->value}] returned null but is not classified in NO_PUSH_VOICE.");

            continue;
        }

        expect(array_key_exists($category->value, NotificationsPushTestCommand::NO_PUSH_VOICE))
            ->toBeFalse("[{$category->value}] is classified in NO_PUSH_VOICE but notificationFor() returns a notification — pick one.");

        expect(method_exists($notification, 'toPush'))
            ->toBeTrue("[{$category->value}] maps to ".$notification::class.', which has no toPush() — move it to NO_PUSH_VOICE or pick a class that carries a push voice.');
    }

    // No stale entries: exactly the no-voice categories null out.
    expect($withoutVoice)
        ->toHaveCount(count(NotificationsPushTestCommand::NO_PUSH_VOICE))
        ->and($withoutVoice)->toBe(array_keys(NotificationsPushTestCommand::NO_PUSH_VOICE));
});
