<?php

namespace Tests\Feature\Services;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Models\UserRelationship;
use App\Notifications\Channels\PushChannel;
use App\Services\NotificationService;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

describe('NotificationService', function () {
    beforeEach(function () {
        $this->service = new NotificationService();
    });

    // ── resolveChannels ──────────────────────────────────────────

    describe('resolveChannels', function () {
        it('returns both channels when both are enabled in settings', function () {
            $user = User::factory()->create([
                'notification_settings' => [
                    'game_invitation' => ['database' => true, 'mail' => true],
                ],
            ]);

            $channels = $this->service->resolveChannels($user, NotificationCategory::GameInvitation);

            expect($channels)->toHaveKey('database');
            expect($channels)->toHaveKey('mail');
            expect($channels['database'])->toBe(DatabaseChannel::class);
            expect($channels['mail'])->toBe(MailChannel::class);
        });

        it('returns single channel when the other is disabled', function (string $enabled, string $disabled) {
            $settings = [
                'game_invitation' => ['database' => true, 'mail' => true],
            ];
            $settings['game_invitation'][$disabled] = false;

            $user = User::factory()->create(['notification_settings' => $settings]);

            $channels = $this->service->resolveChannels($user, NotificationCategory::GameInvitation);

            expect($channels)->toHaveKey($enabled);
            expect($channels)->not->toHaveKey($disabled);
        })->with([
            'database only' => ['database', 'mail'],
            'mail only' => ['mail', 'database'],
        ]);

        it('returns empty array when both channels are disabled', function () {
            $user = User::factory()->create([
                'notification_settings' => [
                    'game_invitation' => ['database' => false, 'mail' => false],
                ],
            ]);

            $channels = $this->service->resolveChannels($user, NotificationCategory::GameInvitation);

            expect($channels)->toBeEmpty();
        });

        it('falls back to defaults when notification_settings is null', function () {
            $user = User::factory()->create(['notification_settings' => null]);

            $channels = $this->service->resolveChannels($user, NotificationCategory::GameInvitation);

            // GameInvitation defaults: database=true, mail=true, push=true
            expect($channels)->toHaveCount(3);
            expect($channels)->toHaveKey('database');
            expect($channels)->toHaveKey('mail');
            expect($channels)->toHaveKey('push');
        });

        it('falls back to defaults when category key is missing from settings', function () {
            $user = User::factory()->create([
                'notification_settings' => [
                    'new_follower' => ['database' => true, 'mail' => false],
                    // game_invitation is missing
                ],
            ]);

            $channels = $this->service->resolveChannels($user, NotificationCategory::GameInvitation);

            // Falls back to GameInvitation defaults: database=true, mail=true, push=true
            expect($channels)->toHaveCount(3);
            expect($channels)->toHaveKey('push');
        });

        it('falls back to defaults when category value is malformed', function () {
            Log::shouldReceive('warning')->once();

            $user = User::factory()->create([
                'notification_settings' => [
                    'game_invitation' => 'not_an_array',
                ],
            ]);

            $channels = $this->service->resolveChannels($user, NotificationCategory::GameInvitation);

            // Should fall back to defaults and log a warning
            expect($channels)->toHaveCount(3);
            expect($channels)->toHaveKey('push');
        });

        it('uses correct defaults for categories where mail defaults to false', function () {
            $user = User::factory()->create(['notification_settings' => null]);

            $channels = $this->service->resolveChannels($user, NotificationCategory::NewFollower);

            // NewFollower defaults: database=true, mail=false
            expect($channels)->toHaveCount(1);
            expect($channels)->toHaveKey('database');
        });

        it('handles push channel based on settings', function (bool $pushEnabled, int $expectedCount) {
            $user = User::factory()->create([
                'notification_settings' => [
                    'game_invitation' => ['database' => true, 'mail' => true, 'push' => $pushEnabled],
                ],
            ]);

            $channels = $this->service->resolveChannels($user, NotificationCategory::GameInvitation);

            expect($channels)->toHaveCount($expectedCount);
            if ($pushEnabled) {
                expect($channels)->toHaveKey('push');
                expect($channels['push'])->toBe(PushChannel::class);
            } else {
                expect($channels)->not->toHaveKey('push');
            }
        })->with([
            'push enabled' => [true, 3],
            'push disabled' => [false, 2],
        ]);
    });

    // ── send ─────────────────────────────────────────────────────

    describe('send', function () {
        it('dispatches notification via notifyNow with resolved channels', function () {
            $user = User::factory()->create([
                'notification_settings' => [
                    'game_invitation' => ['database' => true, 'mail' => false],
                ],
            ]);

            $notification = new TestNotification(['game_id' => 1]);
            $this->service->send($user, $notification, NotificationCategory::GameInvitation);

            // The notification should have been stored in the database (database channel)
            expect($user->notifications)->toHaveCount(1);
            expect($user->notifications->first()->type)->toBe(TestNotification::class);
        });

        it('skips dispatch when all channels are disabled', function () {
            Log::shouldReceive('info')->with('notification.dispatch_skipped', \Mockery::type('array'));

            $user = User::factory()->create([
                'notification_settings' => [
                    'game_invitation' => ['database' => false, 'mail' => false],
                ],
            ]);

            $notification = new TestNotification(['game_id' => 1]);
            $this->service->send($user, $notification, NotificationCategory::GameInvitation);

            expect($user->fresh()->notifications)->toHaveCount(0);
        });

        it('skips dispatch when notifiable has blocked the actor', function () {
            Log::shouldReceive('info')->with('notification.dispatch_skipped', \Mockery::type('array'));

            $actor = User::factory()->create();
            $notifiable = User::factory()->create([
                'notification_settings' => [
                    'game_invitation' => ['database' => true, 'mail' => true],
                ],
            ]);

            // Notifiable blocks the actor
            UserRelationship::create([
                'user_id' => $notifiable->id,
                'related_user_id' => $actor->id,
                'type' => 'block',
            ]);

            $notification = new TestNotificationWithActor(['game_id' => 1], $actor);
            $this->service->send($notifiable, $notification, NotificationCategory::GameInvitation);

            expect($notifiable->fresh()->notifications)->toHaveCount(0);
        });

        it('dispatches when notification has no getActor method', function () {
            $user = User::factory()->create([
                'notification_settings' => [
                    'game_completed' => ['database' => true, 'mail' => false],
                ],
            ]);

            $notification = new TestNotification(['game_id' => 1]);
            $this->service->send($user, $notification, NotificationCategory::GameCompleted);

            expect($user->notifications)->toHaveCount(1);
        });

        it('logs dispatched notification with type, target, channels', function () {
            $user = User::factory()->create([
                'notification_settings' => [
                    'game_invitation' => ['database' => true, 'mail' => false],
                ],
            ]);

            $capturedContext = null;
            Log::shouldReceive('info')->with('notification.dispatched', \Mockery::capture($capturedContext));

            $notification = new TestNotification(['game_id' => 1]);
            $this->service->send($user, $notification, NotificationCategory::GameInvitation);

            expect($capturedContext['notifiable_id'])->toBe($user->id);
            expect($capturedContext['category'])->toBe('game_invitation');
            expect($capturedContext['channels'])->toContain('database');
        });

        it('catches exceptions and logs errors without throwing', function () {
            Log::shouldReceive('error')->once();

            $user = User::factory()->create([
                'notification_settings' => [
                    'game_invitation' => ['database' => true, 'mail' => false],
                ],
            ]);

            $notification = new class(['game_id' => 1]) extends TestNotification
            {
                public function toDatabase($notifiable): array
                {
                    throw new \RuntimeException('Database channel failed');
                }
            };

            // Should NOT throw
            $this->service->send($user, $notification, NotificationCategory::GameInvitation);
        });
    });

    // ── markReadByType ───────────────────────────────────────────

    describe('markReadByType', function () {
        it('marks unread notifications of a specific type as read', function () {
            $user = User::factory()->create();

            // Create two notifications of the same type
            $user->notifyNow(new TestNotification(['entity_id' => 1]));
            $user->notifyNow(new TestNotification(['entity_id' => 2]));
            // Create a notification of a different type
            $user->notifyNow(new TestNotificationB(['entity_id' => 3]));

            expect($user->fresh()->unreadNotifications)->toHaveCount(3);

            $this->service->markReadByType($user, TestNotification::class);

            $freshUser = $user->fresh();
            // Only TestNotification should be marked read
            $unread = $freshUser->unreadNotifications;
            expect($unread)->toHaveCount(1);
            expect($unread->first()->type)->toBe(TestNotificationB::class);
        });

        it('marks unread notifications filtered by entity ID', function () {
            $user = User::factory()->create();

            $user->notifyNow(new TestNotification(['entity_id' => 10]));
            $user->notifyNow(new TestNotification(['entity_id' => 20]));
            $user->notifyNow(new TestNotification(['entity_id' => 30]));

            $this->service->markReadByType($user, TestNotification::class, 20);

            $freshUser = $user->fresh();
            $unread = $freshUser->unreadNotifications;
            expect($unread)->toHaveCount(2);

            $readNotification = $freshUser->readNotifications->first();
            expect($readNotification->data['entity_id'])->toBe(20);
        });

        it('does nothing when no matching notifications exist', function () {
            $user = User::factory()->create();

            // No notifications at all
            $this->service->markReadByType($user, TestNotification::class);

            expect($user->fresh()->unreadNotifications)->toHaveCount(0);
        });
    });

    // ── getGroupedRecent ─────────────────────────────────────────

    describe('getGroupedRecent', function () {
        it('groups notifications by read/unread status', function () {
            $user = User::factory()->create();

            $user->notifyNow(new TestNotification(['entity_id' => 1]));
            $user->notifyNow(new TestNotification(['entity_id' => 2]));
            $user->notifyNow(new TestNotification(['entity_id' => 3]));

            // Mark the first as read
            $user->notifications->first()->markAsRead();

            $result = $this->service->getGroupedRecent($user);

            expect($result)->toHaveKeys(['read', 'unread']);
            expect($result['read'])->toHaveCount(1);
            expect($result['unread'])->toHaveCount(2);
        });

        it('respects the limit parameter', function () {
            $user = User::factory()->create();

            for ($i = 1; $i <= 5; $i++) {
                $user->notifyNow(new TestNotification(['entity_id' => $i]));
            }

            $result = $this->service->getGroupedRecent($user, 3);

            $total = $result->flatten()->count();
            expect($total)->toBe(3);
        });

        it('returns notifications sorted by created_at desc', function () {
            $user = User::factory()->create();

            $user->notifyNow(new TestNotification(['entity_id' => 1]));
            $this->travelTo(now()->addSecond());
            $user->notifyNow(new TestNotification(['entity_id' => 2]));

            $result = $this->service->getGroupedRecent($user);

            $all = $result->flatten();
            expect($all->first()->data['entity_id'])->toBe(2);
        });
    });
});

// ── Test Notification Classes ────────────────────────────────────

class TestNotification extends \Illuminate\Notifications\Notification
{
    public function __construct(protected array $data = []) {}

    public function via(object $notifiable): array
    {
        return [DatabaseChannel::class, MailChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->data;
    }

    public function toMail(object $notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->line('Test notification');
    }
}

class TestNotificationWithActor extends TestNotification
{
    public function __construct(array $data, protected User $actor)
    {
        parent::__construct($data);
    }

    public function getActor(): User
    {
        return $this->actor;
    }
}

class TestNotificationB extends TestNotification {}
