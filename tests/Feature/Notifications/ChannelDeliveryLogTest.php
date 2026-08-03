<?php

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Notifications\AccountSuspended;
use App\Notifications\Channels\PushChannel;
use App\Services\NotificationService;
use Illuminate\Notifications\Events\NotificationFailed;
use Tests\Traits\CapturesLogRecords;

uses(CapturesLogRecords::class);

beforeEach(function () {
    // Attach the capture-only Monolog handler before any notification dispatch.
    $this->captureLogRecords();
});

// ══════════════════════════════════════════════════════
// LogNotificationDelivery listener (NotificationSent / NotificationFailed)
//
// Fills the per-channel delivery observability gap: NotificationService logs
// dispatch (enqueue) intent, but the actual channel send runs on the worker.
// The listener turns Laravel's per-channel NotificationSent/Failed events into
// a structured delivery record across database/mail/push/discord.
// ══════════════════════════════════════════════════════

it('logs notification.channel_delivered when a channel send succeeds', function () {
    $user = User::factory()->create([
        'notification_settings' => [
            'moderation_notice' => [
                'database' => true,
                'mail' => false,
                'push' => false,
                'discord' => false,
            ],
        ],
    ]);

    app(NotificationService::class)->send(
        $user,
        new AccountSuspended('investigation'),
        NotificationCategory::ModerationNotice,
    );

    $record = $this->logRecord('notification.channel_delivered');

    expect($record)->not->toBeNull('NotificationSent should fire for the database channel and be logged')
        ->and($record['context']['channel'])->toBe('database')
        ->and($record['context']['notification_type'])->toBe(AccountSuspended::class)
        ->and($record['context']['notifiable_id'])->toBe($user->id);
})->group('smoke');

it('logs notification.channel_failed with the channel and error when a channel send fails', function () {
    $user = User::factory()->create();
    $notification = new AccountSuspended('investigation');

    event(new NotificationFailed($user, $notification, 'mail', ['exception' => new RuntimeException('SMTP timeout')]));

    $record = $this->logRecord('notification.channel_failed');

    expect($record)->not->toBeNull()
        ->and($record['context']['channel'])->toBe('mail')
        ->and($record['context']['notification_type'])->toBe(AccountSuspended::class)
        ->and($record['context']['error'])->toBe('SMTP timeout')
        ->and($record['context']['exception'])->toBe(RuntimeException::class);
})->group('smoke');

it('aliases each channel class to a short name for readable logs', function () {
    $user = User::factory()->create();
    $notification = new AccountSuspended('investigation');

    event(new NotificationFailed($user, $notification, PushChannel::class, ['exception' => new RuntimeException('x')]));

    $record = $this->logRecord('notification.channel_failed');

    expect($record['context']['channel'])->toBe('push');
})->group('smoke');
