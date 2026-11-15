<?php

use App\Enums\NotificationCategory;
use App\Models\EmailSuppression;
use App\Models\NotificationEngagementCounter;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
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
    // Freeze the clock: counter rows bucket on the UTC calendar date of the
    // event instant, so a test straddling UTC midnight would write and read
    // different daily rows and false-fail (same hazard class the frozen-clock
    // leak guard exists for).
    $this->travelTo(Carbon::parse('2026-11-14 12:00:00'));
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

/**
 * Engagement-webhook payload whose data.tags carry the roundup_category
 * dispatch tag (the shape TaggedMailChannel stamps and Resend echoes back,
 * per resend.com/docs/webhooks/emails/delivered). Null $category omits tags.
 *
 * @param  array<string, mixed>  $extraData
 */
function engagementPayload(string $type, ?string $category = null, array $extraData = []): string
{
    $data = array_merge([
        'email_id' => 'e-counter',
        'message_id' => '<c-1@x.com>',
        'to' => ['reader@example.com'],
        'subject' => 'Your game tonight',
    ], $extraData);

    if ($category !== null) {
        $data['tags'] = ['roundup_category' => $category];
    }

    return json_encode(['type' => $type, 'created_at' => now()->toIso8601String(), 'data' => $data]);
}

/** Today's mail counter for one (category, event) — the D284 aggregate row. */
function counterCount(string $category, string $event): int
{
    return (int) NotificationEngagementCounter::query()
        ->where('date', now()->toDateString())
        ->where('category', $category)
        ->where('channel', 'mail')
        ->where('event', $event)
        ->value('count');
}

// ══════════════════════════════════════════════════════
// Signature verification — fail closed
// ══════════════════════════════════════════════════════

it('rejects a request with no svix-signature header', function () {
    $body = bouncedPayload('bounce@example.com');
    $svix = svixHeaders($body, $this->webhookSecret);

    postResendWebhook($body, $svix, dropSignature: true)->assertStatus(400);

    expect(EmailSuppression::count())->toBe(0)
        ->and(NotificationEngagementCounter::count())->toBe(0); // nothing runs before verification
});

it('rejects a request signed with the wrong secret', function () {
    $body = bouncedPayload('bounce@example.com');
    $svix = svixHeaders($body, 'whsec_'.base64_encode(random_bytes(32))); // different secret

    postResendWebhook($body, $svix)->assertStatus(400);

    expect(EmailSuppression::count())->toBe(0);
});

it('rejects a request whose timestamp is outside replay tolerance', function () {
    $body = bouncedPayload('bounce@example.com');
    $svix = svixHeaders($body, $this->webhookSecret, timestamp: (string) (time() - 600)); // > 5 min

    postResendWebhook($body, $svix)->assertStatus(400);
});

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
});

it('does not suppress on a non-permanent bounce type', function () {
    $body = bouncedPayload('soft@example.com', bounceType: 'Transient');
    $svix = svixHeaders($body, $this->webhookSecret);

    postResendWebhook($body, $svix)->assertStatus(200);

    expect(EmailSuppression::count())->toBe(0)
        ->and($this->logRecord('mail.bounced'))->not->toBeNull(); // still logged for telemetry
});

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
});

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
});

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
});

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
});

it('keeps the mail channel for a clean address', function () {
    $user = User::factory()->create([
        'email' => 'clean@example.com',
        'notification_settings' => [
            'session_reminder' => ['database' => true, 'mail' => true, 'push' => false, 'discord' => false],
        ],
    ]);

    $channels = app(NotificationService::class)->resolveChannels($user, NotificationCategory::SessionReminder);

    expect($channels)->toHaveKey('mail');
});

// ══════════════════════════════════════════════════════
// Retryable failure — suppression is the load-bearing side effect
// ══════════════════════════════════════════════════════

it('returns 500 so Resend retries when a bounce suppression write fails', function () {
    // Simulate a transient DB write failure: an email longer than the
    // varchar(255) column forces a QueryException inside firstOrCreate's INSERT
    // (Postgres enforces varchar length deterministically; the test DB is pgsql).
    // No static event listeners are mutated, so the test stays isolated.
    $email = str_repeat('a', 300).'@example.com';
    $body = bouncedPayload($email);
    $svix = svixHeaders($body, $this->webhookSecret);

    postResendWebhook($body, $svix)->assertStatus(500);

    expect(EmailSuppression::count())->toBe(0)
        ->and(NotificationEngagementCounter::count())->toBe(0) // counted only after suppression succeeded — no retry double-count
        ->and($this->logRecord('resend.webhook_processing_failed'))->not->toBeNull();
});

it('still returns 200 when a telemetry-only (delivered) event fails processing', function () {
    // Telemetry events stay best-effort: a processing failure there must NOT
    // trigger a Resend retry (that would only add noise). The delivered path
    // has no throwing collaborator of its own, so the failure is injected at
    // the one seam every handler touches: the Log facade. The thrown error
    // reaches the controller's outer catch, whose type dispatch must answer
    // 200 for non-suppression events — paired with the bounce test above,
    // this pins the 500 specifically to suppression-bearing events.
    $body = json_encode([
        'type' => 'email.delivered',
        'created_at' => now()->toIso8601String(),
        'data' => ['email_id' => 'e3', 'message_id' => '<d-3@x.com>', 'to' => ['ok@example.com'], 'subject' => 's'],
    ]);
    $svix = svixHeaders($body, $this->webhookSecret);

    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message): bool => $message === 'mail.delivered')
        ->andThrow(new RuntimeException('log transport down'));
    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message): bool => $message === 'resend.webhook_processing_failed');
    Log::shouldReceive('warning')->zeroOrMoreTimes();

    postResendWebhook($body, $svix)->assertStatus(200);

    // The counter write is never reached (handle() threw before it).
    expect(NotificationEngagementCounter::count())->toBe(0);
});

// ══════════════════════════════════════════════════════
// Engagement counters (D284) — one signed case per event type
// ══════════════════════════════════════════════════════

it('counts a delivered event under its tagged category', function () {
    $body = engagementPayload('email.delivered', 'session_reminder');
    $svix = svixHeaders($body, $this->webhookSecret);

    postResendWebhook($body, $svix)->assertStatus(200);

    expect(counterCount('session_reminder', 'delivered'))->toBe(1)
        ->and(EmailSuppression::count())->toBe(0);
});

it('counts an opened event under its tagged category', function () {
    $body = engagementPayload('email.opened', 'game_invitation', [
        'user_agent' => 'Mozilla/5.0',
        'ip' => '203.0.113.9',
    ]);
    $svix = svixHeaders($body, $this->webhookSecret);

    postResendWebhook($body, $svix)->assertStatus(200);

    expect(counterCount('game_invitation', 'opened'))->toBe(1)
        ->and($this->logRecord('mail.opened'))->not->toBeNull()
        ->and(EmailSuppression::count())->toBe(0);
});

it('counts a clicked event under its tagged category', function () {
    $body = engagementPayload('email.clicked', 'waitlist_promoted', [
        'click' => ['url' => 'https://roundup.test/games/42', 'timestamp' => now()->timestamp],
    ]);
    $svix = svixHeaders($body, $this->webhookSecret);

    postResendWebhook($body, $svix)->assertStatus(200);

    expect(counterCount('waitlist_promoted', 'clicked'))->toBe(1)
        ->and($this->logRecord('mail.clicked'))->not->toBeNull()
        ->and(EmailSuppression::count())->toBe(0);
});

it('counts a failed event under its tagged category without suppressing', function () {
    $body = engagementPayload('email.failed', 'session_reminder', [
        'error' => ['message' => 'Email address is invalid', 'name' => 'validation_error'],
    ]);
    $svix = svixHeaders($body, $this->webhookSecret);

    postResendWebhook($body, $svix)->assertStatus(200);

    expect(counterCount('session_reminder', 'failed'))->toBe(1)
        ->and($this->logRecord('mail.failed'))->not->toBeNull()
        ->and(EmailSuppression::count())->toBe(0);
});

it('counts a permanent bounce under its tagged category and still suppresses', function () {
    $body = engagementPayload('email.bounced', 'digest', [
        'bounce' => ['message' => 'mailbox does not exist', 'type' => 'Permanent'],
    ]);
    $svix = svixHeaders($body, $this->webhookSecret);

    postResendWebhook($body, $svix)->assertStatus(200);

    expect(EmailSuppression::where('email', 'reader@example.com')->value('reason'))->toBe('hard_bounce')
        ->and(counterCount('digest', 'bounced'))->toBe(1)
        ->and($this->logRecord('mail.suppressed'))->not->toBeNull();
});

it('counts a complaint under its tagged category and still suppresses', function () {
    $body = engagementPayload('email.complained', 'session_reminder');
    $svix = svixHeaders($body, $this->webhookSecret);

    postResendWebhook($body, $svix)->assertStatus(200);

    expect(EmailSuppression::where('email', 'reader@example.com')->value('reason'))->toBe('complaint')
        ->and(counterCount('session_reminder', 'complained'))->toBe(1);
});

it('counts a transient bounce without suppressing', function () {
    $body = engagementPayload('email.bounced', 'game_invitation', [
        'bounce' => ['message' => 'mailbox full', 'type' => 'Transient'],
    ]);
    $svix = svixHeaders($body, $this->webhookSecret);

    postResendWebhook($body, $svix)->assertStatus(200);

    expect(EmailSuppression::count())->toBe(0)
        ->and(counterCount('game_invitation', 'bounced'))->toBe(1);
});

it('counts untagged and foreign-tagged events under the untagged category', function () {
    $noTags = engagementPayload('email.opened'); // no tags key at all
    postResendWebhook($noTags, svixHeaders($noTags, $this->webhookSecret))->assertStatus(200);

    $foreignTags = engagementPayload('email.opened', null, ['tags' => ['newsletter' => 'weekly']]);
    postResendWebhook($foreignTags, svixHeaders($foreignTags, $this->webhookSecret))->assertStatus(200);

    expect(counterCount('untagged', 'opened'))->toBe(2);
});

it('accumulates repeated webhook events on one aggregate row, never per-event rows', function () {
    $body = engagementPayload('email.delivered', 'session_reminder');

    postResendWebhook($body, svixHeaders($body, $this->webhookSecret))->assertStatus(200);
    postResendWebhook($body, svixHeaders($body, $this->webhookSecret))->assertStatus(200);

    expect(NotificationEngagementCounter::count())->toBe(1)
        ->and(counterCount('session_reminder', 'delivered'))->toBe(2);
});

it('keeps returning 200 when the counter write itself fails', function () {
    // A category longer than the varchar(64) column forces a QueryException
    // inside the upsert (Postgres enforces varchar length; the test DB is pgsql)
    // — same deterministic mechanism as the suppression-failure test above.
    $body = engagementPayload('email.delivered', str_repeat('x', 300));
    $svix = svixHeaders($body, $this->webhookSecret);

    postResendWebhook($body, $svix)->assertStatus(200);

    expect(NotificationEngagementCounter::count())->toBe(0)
        ->and($this->logRecord('mail.delivered'))->not->toBeNull() // telemetry logging still ran
        ->and($this->logRecord('resend.engagement_counter_failed'))->not->toBeNull();
});
