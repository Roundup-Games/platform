<?php

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Create a Mockery mock of User that handles Eloquent's dynamic property access.
 *
 * Eloquent's __get/__set delegate to getAttribute/setAttribute, which Mockery
 * intercepts. This helper sets up the common expectations needed for
 * NotificationService::send() to work without a real database.
 */
function mockNotifiableUser(int $id, array $settings): Mockery\MockInterface&User
{
    $user = Mockery::mock(User::class);
    $user->shouldReceive('getAttribute')->with('id')->andReturn($id);
    $user->shouldReceive('getAttribute')->with('notification_settings')->andReturn($settings);
    $user->shouldReceive('setAttribute')->zeroOrMoreTimes();

    return $user;
}

describe('NotificationService Unit Tests', function () {
    beforeEach(function () {
        $this->service = new NotificationService();
    });

    // ── resolveChannels ──────────────────────────────────────────

    describe('resolveChannels', function () {
        it('returns both channels when both are enabled', function () {
            $user = Mockery::mock(User::class);
            $user->shouldReceive('getAttribute')->with('notification_settings')->andReturn([
                'game_invitation' => ['database' => true, 'mail' => true],
            ]);

            $channels = $this->service->resolveChannels($user, NotificationCategory::GameInvitation);

            expect($channels)->toHaveCount(2);
            expect($channels)->toBe(['database' => DatabaseChannel::class, 'mail' => MailChannel::class]);
        });

        it('returns only database when in-app ON and mail OFF', function () {
            $user = Mockery::mock(User::class);
            $user->shouldReceive('getAttribute')->with('notification_settings')->andReturn([
                'new_follower' => ['database' => true, 'mail' => false],
            ]);

            $channels = $this->service->resolveChannels($user, NotificationCategory::NewFollower);

            expect($channels)->toBe(['database' => DatabaseChannel::class]);
        });

        it('returns only mail when database OFF and mail ON', function () {
            $user = Mockery::mock(User::class);
            $user->shouldReceive('getAttribute')->with('notification_settings')->andReturn([
                'game_invitation' => ['database' => false, 'mail' => true],
            ]);

            $channels = $this->service->resolveChannels($user, NotificationCategory::GameInvitation);

            expect($channels)->toBe(['mail' => MailChannel::class]);
        });

        it('returns empty array when both channels OFF', function () {
            $user = Mockery::mock(User::class);
            $user->shouldReceive('getAttribute')->with('notification_settings')->andReturn([
                'game_invitation' => ['database' => false, 'mail' => false],
            ]);

            $channels = $this->service->resolveChannels($user, NotificationCategory::GameInvitation);

            expect($channels)->toBeEmpty();
        });

        it('falls back to defaults when notification_settings is null', function () {
            $user = Mockery::mock(User::class);
            $user->shouldReceive('getAttribute')->with('notification_settings')->andReturn(null);

            $channels = $this->service->resolveChannels($user, NotificationCategory::GameInvitation);

            // GameInvitation default: database=true, mail=true, push=true
            expect($channels)->toHaveCount(3);
            expect($channels)->toHaveKey('database');
            expect($channels)->toHaveKey('mail');
            expect($channels)->toHaveKey('push');
        });

        it('falls back to defaults for categories where mail and push default to false', function () {
            $user = Mockery::mock(User::class);
            $user->shouldReceive('getAttribute')->with('notification_settings')->andReturn(null);

            $channels = $this->service->resolveChannels($user, NotificationCategory::NewFollower);

            // NewFollower default: database=true, mail=false, push=false
            expect($channels)->toBe(['database' => DatabaseChannel::class]);
        });

        it('falls back to defaults when category key is missing from settings', function () {
            $user = Mockery::mock(User::class);
            $user->shouldReceive('getAttribute')->with('notification_settings')->andReturn([
                'new_follower' => ['database' => true, 'mail' => false],
                // game_invitation missing
            ]);

            $channels = $this->service->resolveChannels($user, NotificationCategory::GameInvitation);

            // GameInvitation default includes push=true
            expect($channels)->toHaveCount(3);
            expect($channels)->toHaveKey('database');
            expect($channels)->toHaveKey('mail');
            expect($channels)->toHaveKey('push');
        });

        it('falls back to defaults when category value is malformed and logs warning', function () {
            Log::shouldReceive('warning')->once()->with(
                'notification.malformed_settings',
                Mockery::type('array')
            );

            $user = Mockery::mock(User::class);
            $user->shouldReceive('getAttribute')->with('notification_settings')->andReturn([
                'game_invitation' => 'not_an_array',
            ]);
            $user->shouldReceive('getAttribute')->with('id')->andReturn(1);
            $user->shouldReceive('setAttribute')->zeroOrMoreTimes();

            $channels = $this->service->resolveChannels($user, NotificationCategory::GameInvitation);

            // Falls back to GameInvitation defaults: database=true, mail=true, push=true
            expect($channels)->toHaveCount(3);
        });

        it('falls back to defaults when category value is an integer', function () {
            Log::shouldReceive('warning')->once();

            $user = Mockery::mock(User::class);
            $user->shouldReceive('getAttribute')->with('notification_settings')->andReturn([
                'new_follower' => 42,
            ]);
            $user->shouldReceive('getAttribute')->with('id')->andReturn(1);
            $user->shouldReceive('setAttribute')->zeroOrMoreTimes();

            $channels = $this->service->resolveChannels($user, NotificationCategory::NewFollower);

            expect($channels)->toHaveCount(1);
            expect($channels)->toHaveKey('database');
        });

        it('returns correct channels for all 15 categories with null settings', function () {
            $defaults = NotificationCategory::defaultSettings();

            foreach (NotificationCategory::cases() as $category) {
                $user = Mockery::mock(User::class);
                $user->shouldReceive('getAttribute')->with('notification_settings')->andReturn(null);

                $channels = $this->service->resolveChannels($user, $category);
                $expectedCount = count(array_filter($defaults[$category->value], fn ($v) => $v));

                expect($channels)->toHaveCount($expectedCount, "{$category->value} should resolve {$expectedCount} channels");
            }
        });

        it('includes push channel when push is enabled in settings', function () {
            $user = Mockery::mock(User::class);
            $user->shouldReceive('getAttribute')->with('notification_settings')->andReturn([
                'game_invitation' => ['database' => true, 'mail' => true, 'push' => true],
            ]);

            $channels = $this->service->resolveChannels($user, NotificationCategory::GameInvitation);

            expect($channels)->toHaveCount(3);
            expect($channels)->toHaveKey('push');
            expect($channels['push'])->toBe(\App\Notifications\Channels\PushChannel::class);
        });

        it('excludes push channel when push is disabled', function () {
            $user = Mockery::mock(User::class);
            $user->shouldReceive('getAttribute')->with('notification_settings')->andReturn([
                'game_invitation' => ['database' => true, 'mail' => true, 'push' => false],
            ]);

            $channels = $this->service->resolveChannels($user, NotificationCategory::GameInvitation);

            expect($channels)->toHaveCount(2);
            expect($channels)->not->toHaveKey('push');
        });
    });

    // ── send ─────────────────────────────────────────────────────

    describe('send', function () {
        it('dispatches notification via notifyNow with resolved channels', function () {
            $user = mockNotifiableUser(1, [
                'game_invitation' => ['database' => true, 'mail' => false],
            ]);
            $user->shouldReceive('notifyNow')
                ->once()
                ->with(Mockery::type(Notification::class), ['database' => DatabaseChannel::class]);

            Log::shouldReceive('info')->once()->with('notification.dispatched', Mockery::type('array'));

            $notification = new class extends Notification
            {
                public function via(object $notifiable): array
                {
                    return [DatabaseChannel::class];
                }

                public function toDatabase(object $notifiable): array
                {
                    return ['test' => true];
                }
            };

            $this->service->send($user, $notification, NotificationCategory::GameInvitation);
        });

        it('skips dispatch when all channels OFF and logs skipped', function () {
            $user = mockNotifiableUser(1, [
                'game_invitation' => ['database' => false, 'mail' => false],
            ]);
            $user->shouldNotReceive('notifyNow');

            Log::shouldReceive('info')->once()->with('notification.dispatch_skipped', Mockery::on(function ($ctx) {
                return ($ctx['reason'] ?? null) === 'all_channels_disabled';
            }));

            $notification = new class extends Notification
            {
                public function via(object $notifiable): array
                {
                    return [];
                }
            };

            $this->service->send($user, $notification, NotificationCategory::GameInvitation);
        });

        it('skips dispatch when notifiable has blocked the actor and logs skipped', function () {
            $actor = Mockery::mock(User::class);
            $actor->shouldReceive('setAttribute')->zeroOrMoreTimes();
            $actor->shouldReceive('getAttribute')->with('id')->andReturn(99);

            $user = mockNotifiableUser(1, [
                'game_invitation' => ['database' => true, 'mail' => true],
            ]);
            $user->shouldReceive('hasBlocked')->with($actor)->andReturn(true);
            $user->shouldNotReceive('notifyNow');

            Log::shouldReceive('info')->once()->with('notification.dispatch_skipped', Mockery::on(function ($ctx) {
                return ($ctx['reason'] ?? null) === 'blocked_actor';
            }));

            $notification = new class($actor) extends Notification
            {
                public function __construct(private User $actor) {}

                public function via(object $notifiable): array
                {
                    return [DatabaseChannel::class];
                }

                public function getActor(): User
                {
                    return $this->actor;
                }
            };

            $this->service->send($user, $notification, NotificationCategory::GameInvitation);
        });

        it('dispatches when notification has no getActor method (no block check)', function () {
            $user = mockNotifiableUser(1, [
                'game_completed' => ['database' => true, 'mail' => false],
            ]);
            $user->shouldReceive('notifyNow')->once();

            Log::shouldReceive('info')->once();

            $notification = new class extends Notification
            {
                public function via(object $notifiable): array
                {
                    return [DatabaseChannel::class];
                }
            };

            $this->service->send($user, $notification, NotificationCategory::GameCompleted);
        });

        it('dispatches when actor exists but is not blocked', function () {
            $actor = Mockery::mock(User::class);
            $actor->shouldReceive('setAttribute')->zeroOrMoreTimes();
            $actor->shouldReceive('getAttribute')->with('id')->andReturn(99);

            $user = mockNotifiableUser(1, [
                'game_invitation' => ['database' => true, 'mail' => true],
            ]);
            $user->shouldReceive('hasBlocked')->with($actor)->andReturn(false);
            $user->shouldReceive('notifyNow')->once();

            Log::shouldReceive('info')->once();

            $notification = new class($actor) extends Notification
            {
                public function __construct(private User $actor) {}

                public function via(object $notifiable): array
                {
                    return [DatabaseChannel::class];
                }

                public function getActor(): User
                {
                    return $this->actor;
                }
            };

            $this->service->send($user, $notification, NotificationCategory::GameInvitation);
        });

        it('logs error but does not throw on dispatch failure', function () {
            $user = mockNotifiableUser(1, [
                'game_invitation' => ['database' => true, 'mail' => false],
            ]);
            $user->shouldReceive('notifyNow')->andThrow(new RuntimeException('Database gone'));

            Log::shouldReceive('error')->once()->with('notification.dispatch_failed', Mockery::type('array'));

            $notification = new class extends Notification
            {
                public function via(object $notifiable): array
                {
                    return [DatabaseChannel::class];
                }
            };

            // Must NOT throw
            $this->service->send($user, $notification, NotificationCategory::GameInvitation);

            // Explicit assertion to avoid risky test
            expect(true)->toBeTrue();
        });

        it('logs dispatched notification with correct structured context', function () {
            $user = mockNotifiableUser(42, [
                'game_invitation' => ['database' => true, 'mail' => false],
            ]);
            $user->shouldReceive('notifyNow')->once();

            $capturedContext = null;
            Log::shouldReceive('info')->with('notification.dispatched', Mockery::capture($capturedContext));

            $notification = new class extends Notification
            {
                public function via(object $notifiable): array
                {
                    return [DatabaseChannel::class];
                }
            };

            $this->service->send($user, $notification, NotificationCategory::GameInvitation);

            expect($capturedContext['notifiable_id'])->toBe(42);
            expect($capturedContext['category'])->toBe('game_invitation');
            expect($capturedContext['channels'])->toContain('database');
            expect($capturedContext['notification_type'])->toBeString();
        });

        it('skipped log includes reason, notifiable_id, category, and notification_type', function () {
            $user = mockNotifiableUser(7, [
                'game_invitation' => ['database' => false, 'mail' => false],
            ]);

            $capturedContext = null;
            Log::shouldReceive('info')->with('notification.dispatch_skipped', Mockery::capture($capturedContext));

            $notification = new class extends Notification
            {
                public function via(object $notifiable): array
                {
                    return [];
                }
            };

            $this->service->send($user, $notification, NotificationCategory::GameInvitation);

            expect($capturedContext['reason'])->toBe('all_channels_disabled');
            expect($capturedContext['notifiable_id'])->toBe(7);
            expect($capturedContext['category'])->toBe('game_invitation');
            expect($capturedContext['notification_type'])->toBeString();
        });
    });
});
