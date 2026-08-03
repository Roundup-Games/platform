<?php

use App\Enums\NotificationCategory;
use App\Models\EmailSuppression;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Testing\TestResponse;
use Tests\Traits\CapturesLogRecords;

uses(CapturesLogRecords::class);

/**
 * Resend webhook ingestion + list hygiene (M061).
 *
 * Computes valid Svix signatures server-side so the bundled
 * \Resend\WebhookSignature verifier exercises its real crypto path, not a mock.
 */
beforeEach(function () {
    $this->captureLogRecords();
    config(['services.resend.webhook_secret' => $this->webhookSecret = 'whsec_'.base64_encode(random_bytes(32))]);
});

// ── Svix signing helper — mirrors Resend\WebhookSignature::sign() exactly. ──

function svixHeaders(string $body, string $secret, ?string $timestamp = null): array
{
    $msgId = 'msg_'.bin2hex(random_bytes(12));
    $ts = $timestamp ?? (string) time();

    $key = base64_decode(explode('_', $secret, 2)[1], true);
    $toSign = "{$msgId}.{$ts}.{$body}";
    $signature = base64_encode(pack('H*', hash_hmac('sha256', $toSign, $key)));

    return [
        'msg_id' => $msgId,
        'timestamp' => $ts,
        'signature' => "v1,{$signature}",
    ];
}

function postResendWebhook(string $body, array $svix, bool $dropSignature = false): TestResponse
{
    $headers = [
        'svix-id' => $svix['msg_id'],
        'svix-timestamp' => $svix['timestamp'],
    ];
    if (! $dropSignature) {
        $headers['svix-signature'] = $svix['signature'];
    }

    return test()->call('POST', '/webhooks/resend', server: array_combine(
        array_map(fn ($k) => 'HTTP_'.strtoupper(str_replace('-', '_', $k)), array_keys($headers)),
        array_values($headers)
    ) + ['CONTENT_TYPE' => 'application/json'], content: $body);
}

function bouncedPayload(string $email, string $bounceType = 'Permanent'): string
{
    return json_encode([
        'type' => 'email.bounced',
        'created_at' => now()->toIso8601String(),
        'data' => [
            'email_id' => '11111111-7520-42d8-8898-ff6fc54ce618',
            'message_id' => '<111-222-333@example.com>',
            'to' => [$email],
            'subject' => 'Session reminder',
            'bounce' => ['message' => 'mailbox does not exist', 'type' => $bounceType],
        ],
    ]);
}

// ══════════════════════════════════════════════════════
// Signature verification — fail closed
// ══════════════════════════════════════════════════════

it('rejects a request with no svix-signature header', function () {
    $body = bouncedPayload('bounce@example.com');
    $svix = svixHeaders($body, $this->webhookSecret);

    postResendWebhook($body, $svix, dropSignature: true)->assertStatus(400);

    expect(EmailSuppression::count())->toBe(0);
})->group('smoke');

it('rejects a request signed with the wrong secret', function () {
    $body = bouncedPayload('bounce@example.com');
    $svix = svixHeaders($body, 'whsec_'.base64_encode(random_bytes(32))); // different secret

    postResendWebhook($body, $svix)->assertStatus(400);

    expect(EmailSuppression::count())->toBe(0);
})->group('smoke');

it('rejects a request whose timestamp is outside replay tolerance', function () {
    $body = bouncedPayload('bounce@example.com');
    $svix = svixHeaders($body, $this->webhookSecret, timestamp: (string) (time() - 600)); // > 5 min

    postResendWebhook($body, $svix)->assertStatus(400);
})->group('smoke');

// ══════════════════════════════════════════════════════
// Bounce → suppress
// ══════════════════════════════════════════════════════

it('suppresses the address on a permanent bounce and logs the event', function () {
    $body = bouncedPayload('Bounce@example.com'); // mixed case on purpose
    $svix = svixHeaders($body, $this->webhookSecret);

    postResendWebhook($body, $svix)->assertStatus(200);

    $suppression = EmailSuppression::first();
    expect($suppression)->not->toBeNull()
        ->and($suppression->email)->toBe('bounce@example.com') // stored lowercased
        ->and($suppression->reason)->toBe('hard_bounce')
        ->and($suppression->trigger_message_id)->toBe('<111-222-333@example.com>')
        ->and($this->logRecord('mail.bounced'))->not->toBeNull()
        ->and($this->logRecord('mail.suppressed')['context']['email'])->toBe('bounce@example.com');
})->group('smoke');

it('does not suppress on a non-permanent bounce type', function () {
    $body = bouncedPayload('soft@example.com', bounceType: 'Transient');
    $svix = svixHeaders($body, $this->webhookSecret);

    postResendWebhook($body, $svix)->assertStatus(200);

    expect(EmailSuppression::count())->toBe(0)
        ->and($this->logRecord('mail.bounced'))->not->toBeNull(); // still logged for telemetry
})->group('smoke');

// ══════════════════════════════════════════════════════
// Complaint → suppress; Delivery → log only
// ══════════════════════════════════════════════════════

it('suppresses the address on a spam complaint', function () {
    $body = json_encode([
        'type' => 'email.complained',
        'created_at' => now()->toIso8601String(),
        'data' => ['email_id' => 'e1', 'message_id' => '<c-1@x.com>', 'to' => ['spam@example.com'], 'subject' => 's'],
    ]);
    $svix = svixHeaders($body, $this->webhookSecret);

    postResendWebhook($body, $svix)->assertStatus(200);

    expect(EmailSuppression::where('email', 'spam@example.com')->value('reason'))->toBe('complaint')
        ->and($this->logRecord('mail.suppressed')['context']['reason'])->toBe('complaint');
})->group('smoke');

it('logs delivery without suppressing', function () {
    $body = json_encode([
        'type' => 'email.delivered',
        'created_at' => now()->toIso8601String(),
        'data' => ['email_id' => 'e2', 'message_id' => '<d-1@x.com>', 'to' => ['ok@example.com'], 'subject' => 's'],
    ]);
    $svix = svixHeaders($body, $this->webhookSecret);

    postResendWebhook($body, $svix)->assertStatus(200);

    expect(EmailSuppression::count())->toBe(0)
        ->and($this->logRecord('mail.delivered')['context']['email_id'])->toBe('e2');
})->group('smoke');

// ══════════════════════════════════════════════════════
// Idempotency
// ══════════════════════════════════════════════════════

it('does not duplicate a suppression when the bounce is redelivered', function () {
    $body = bouncedPayload('dup@example.com');
    $svix = svixHeaders($body, $this->webhookSecret);

    postResendWebhook($body, $svix)->assertStatus(200);
    // Resend redelivers the same event (new svix-id/timestamp, same recipient).
    $svix2 = svixHeaders($body, $this->webhookSecret);
    postResendWebhook($body, $svix2)->assertStatus(200);

    expect(EmailSuppression::where('email', 'dup@example.com')->count())->toBe(1);
})->group('smoke');

// ══════════════════════════════════════════════════════
// Mail-channel gating
// ══════════════════════════════════════════════════════

it('drops the mail channel for a suppressed address at dispatch time', function () {
    $user = User::factory()->create([
        'email' => 'suppressed@example.com',
        'notification_settings' => [
            'session_reminder' => ['database' => true, 'mail' => true, 'push' => false, 'discord' => false],
        ],
    ]);
    EmailSuppression::create([
        'email' => 'suppressed@example.com',
        'reason' => 'hard_bounce',
        'source' => 'resend_webhook',
        'suppressed_at' => now(),
    ]);

    $channels = app(NotificationService::class)->resolveChannels($user, NotificationCategory::SessionReminder);

    expect($channels)->not->toHaveKey('mail')
        ->and($channels)->toHaveKey('database'); // other channels unaffected
})->group('smoke');

it('keeps the mail channel for a clean address', function () {
    $user = User::factory()->create([
        'email' => 'clean@example.com',
        'notification_settings' => [
            'session_reminder' => ['database' => true, 'mail' => true, 'push' => false, 'discord' => false],
        ],
    ]);

    $channels = app(NotificationService::class)->resolveChannels($user, NotificationCategory::SessionReminder);

    expect($channels)->toHaveKey('mail');
})->group('smoke');
