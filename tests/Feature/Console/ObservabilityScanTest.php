<?php

use App\Console\Commands\ObservabilityScan;
use App\Mail\ObservabilityAlertMail;
use App\Models\AlertableError;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * observability:scan — the pager half of the log-derived alerting backbone
 * (observability 2026-11-03). The command's contract: ONE consolidated
 * email for unnotified critical-pattern rows that fired within 24h, the
 * last_notified_at watermark so a sighting never pages twice, retirement
 * of stale non-critical noise (7 days), and the optional Discord side
 * channel (needs webhook URL AND page_discord).
 */

beforeEach(function () {
    Mail::fake();
});

it('sends one consolidated email for unnotified critical rows within 24h and watermarks them', function () {
    $criticalA = AlertableError::factory()
        ->critical('badges.sweep.user_failed')
        ->seenAt(now()->subHour())
        ->create();
    $criticalB = AlertableError::factory()
        ->critical('paddle webhook: event registration paid by a different transaction (needs manual reconciliation)')
        ->seenAt(now()->subHours(2))
        ->create();
    // Benign: never matches a pattern, so it rides along in the table but
    // must be neither paged nor resolved (too young for retirement).
    $benign = AlertableError::factory()->seenAt(now()->subHour())->create([
        'message' => 'some benign error',
        'message_hash' => hash('sha256', 'some benign error'),
    ]);

    $this->artisan(ObservabilityScan::class)->assertSuccessful();

    Mail::assertQueued(ObservabilityAlertMail::class, 1);
    Mail::assertQueued(function (ObservabilityAlertMail $mail) use ($criticalA, $criticalB) {
        return $mail->type === 'alert'
            && count($mail->rows) === 2
            && collect($mail->rows)->contains(fn (AlertableError $row): bool => $row->is($criticalA))
            && collect($mail->rows)->contains(fn (AlertableError $row): bool => $row->is($criticalB));
    });

    expect($criticalA->fresh()->last_notified_at)->not->toBeNull()
        ->and($criticalB->fresh()->last_notified_at)->not->toBeNull()
        ->and($benign->fresh()->last_notified_at)->toBeNull()
        ->and($benign->fresh()->resolved_at)->toBeNull();
});

it('stays quiet when no critical pattern fired — and sends nothing', function () {
    AlertableError::factory()->seenAt(now()->subHour())->create([
        'message' => 'some benign error',
        'message_hash' => hash('sha256', 'some benign error'),
    ]);

    $this->artisan(ObservabilityScan::class)->assertSuccessful();

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
    expect(AlertableError::query()->sole()->last_notified_at)->toBeNull();
});

it('ignores critical rows older than the 24h freshness window', function () {
    AlertableError::factory()->critical()->seenAt(now()->subHours(25))->create();

    $this->artisan(ObservabilityScan::class)->assertSuccessful();

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('never re-pages rows the watermark already covers', function () {
    AlertableError::factory()->critical()->notified()->seenAt(now()->subHour())->create();

    $this->artisan(ObservabilityScan::class)->assertSuccessful();

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('ignores already-resolved rows even when fresh and critical', function () {
    AlertableError::factory()->critical()->resolved()->seenAt(now()->subHour())->create();

    $this->artisan(ObservabilityScan::class)->assertSuccessful();

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('resolves stale non-critical rows after 7 days but leaves critical ones open', function () {
    $staleNoise = AlertableError::factory()->seenLongAgo()->create([
        'message' => 'some stale benign error',
        'message_hash' => hash('sha256', 'some stale benign error'),
    ]);
    $staleCritical = AlertableError::factory()->critical('schedule.health.degraded')->seenLongAgo()->create();

    $this->artisan(ObservabilityScan::class)->assertSuccessful();

    expect($staleNoise->fresh()->resolved_at)->not->toBeNull()
        ->and($staleCritical->fresh()->resolved_at)->toBeNull();
});

it('does not double-resolve rows that are already resolved', function () {
    $resolvedAt = now()->subDays(2);
    $row = AlertableError::factory()->create([
        'message' => 'resolved long ago',
        'message_hash' => hash('sha256', 'resolved long ago'),
        'last_seen_at' => now()->subDays(9),
        'first_seen_at' => now()->subDays(9),
        'resolved_at' => $resolvedAt,
    ]);

    $this->artisan(ObservabilityScan::class)->assertSuccessful();

    expect($row->fresh()->resolved_at->format('Y-m-d H:i:s'))->toBe($resolvedAt->format('Y-m-d H:i:s'));
});

it('pages Discord when a webhook is configured and paging is enabled', function () {
    config([
        'observability.discord_webhook_url' => 'https://discord.test/api/webhooks/123/secret',
        'observability.page_discord' => true,
    ]);
    Http::fake();
    AlertableError::factory()->critical()->seenAt(now()->subHour())->create();

    $this->artisan(ObservabilityScan::class)->assertSuccessful();

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://discord.test/api/webhooks/123/secret'
            && str_contains((string) $request['content'] ?? '', 'critical log pattern');
    });
});

it('does not page Discord when paging is disabled even with a webhook configured', function () {
    config(['observability.discord_webhook_url' => 'https://discord.test/api/webhooks/123/secret']);
    Http::fake();
    AlertableError::factory()->critical()->seenAt(now()->subHour())->create();

    $this->artisan(ObservabilityScan::class)->assertSuccessful();

    Mail::assertQueued(ObservabilityAlertMail::class, 1);
    Http::assertNothingSent();
});

it('swallows Discord failures without losing the email page', function () {
    config([
        'observability.discord_webhook_url' => 'https://discord.test/api/webhooks/123/secret',
        'observability.page_discord' => true,
    ]);
    Http::fake(['*' => Http::response('nope', 500)]);
    AlertableError::factory()->critical()->seenAt(now()->subHour())->create();

    $this->artisan(ObservabilityScan::class)->assertSuccessful();

    Mail::assertQueued(ObservabilityAlertMail::class, 1);
    expect(AlertableError::query()->sole()->last_notified_at)->not->toBeNull();
});

it('renders the alert mail with the consolidated row table', function () {
    $row = AlertableError::factory()->critical()->seenAt(now()->subHours(3))->create();

    $rendered = (new ObservabilityAlertMail('alert', [$row]))->render();

    expect($rendered)->toContain('Observability alert')
        ->and($rendered)->toContain('badges.sweep.user_failed');
});
