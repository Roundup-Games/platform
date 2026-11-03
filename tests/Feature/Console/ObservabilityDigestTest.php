<?php

use App\Console\Commands\ObservabilityDigest;
use App\Mail\ObservabilityAlertMail;
use App\Models\AlertableError;
use Illuminate\Support\Facades\Mail;

/*
 * observability:digest — the daily summary half of the log-derived
 * alerting backbone (observability 2026-11-03). Contract: ONE email with
 * the unresolved rows (resolved_at null, most recent first, top 50),
 * grouped by level in the view with occurrence counts, and the
 * last_notified_at watermark on every included row.
 */

beforeEach(function () {
    Mail::fake();
});

it('emails unresolved rows grouped by level and watermarks every included row', function () {
    $error = AlertableError::factory()->critical('badges.sweep.user_failed')->seenAt(now()->subHour())->create();
    $warning = AlertableError::factory()->create([
        'message' => 'needs manual reconciliation',
        'message_hash' => hash('sha256', 'needs manual reconciliation'),
        'level' => 'WARNING',
        'last_seen_at' => now()->subHours(2),
        'first_seen_at' => now()->subHours(2),
    ]);

    $this->artisan(ObservabilityDigest::class)->assertSuccessful();

    Mail::assertQueued(ObservabilityAlertMail::class, 1);
    Mail::assertQueued(function (ObservabilityAlertMail $mail) {
        return $mail->type === 'digest'
            && count($mail->rows) === 2;
    });

    expect($error->fresh()->last_notified_at)->not->toBeNull()
        ->and($warning->fresh()->last_notified_at)->not->toBeNull();
});

it('excludes resolved rows from the digest', function () {
    AlertableError::factory()->resolved()->seenAt(now()->subHour())->create();

    $this->artisan(ObservabilityDigest::class)->assertSuccessful();

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('caps the digest at the top 50 most recent rows and leaves the tail unwatermarked', function () {
    // 55 unresolved rows; the 5 oldest must fall off the cap untouched.
    AlertableError::factory()->count(55)->sequence(fn ($sequence) => [
        'message' => 'error number '.$sequence->index,
        'message_hash' => hash('sha256', 'error number '.$sequence->index),
        'last_seen_at' => now()->subHours(60 - $sequence->index),
        'first_seen_at' => now()->subHours(60 - $sequence->index),
    ])->create();

    $this->artisan(ObservabilityDigest::class)->assertSuccessful();

    Mail::assertQueued(function (ObservabilityAlertMail $mail) {
        return count($mail->rows) === 50;
    });

    expect(AlertableError::query()->whereNull('last_notified_at')->count())->toBe(5)
        // Index 0 is the OLDEST fixture (last_seen 60h ago) — it fell off
        // the top-50 cap and must keep its null watermark.
        ->and(AlertableError::query()->whereNull('last_notified_at')->where('message', 'error number 0')->exists())->toBeTrue()
        ->and(AlertableError::query()->whereNull('last_notified_at')->where('message', 'error number 4')->exists())->toBeTrue()
        ->and(AlertableError::query()->whereNull('last_notified_at')->where('message', 'error number 5')->exists())->toBeFalse();
});

it('sends nothing when there is nothing unresolved', function () {
    $this->artisan(ObservabilityDigest::class)->assertSuccessful();

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('renders the digest mail grouped by level', function () {
    $error = AlertableError::factory()->critical('badges.sweep.user_failed')->seenAt(now()->subHour())->create();
    $warning = AlertableError::factory()->create([
        'message' => 'benign warning',
        'message_hash' => hash('sha256', 'benign warning'),
        'level' => 'WARNING',
        'last_seen_at' => now()->subHours(2),
        'first_seen_at' => now()->subHours(2),
    ]);

    $rendered = (new ObservabilityAlertMail('digest', [$error, $warning]))->render();

    expect($rendered)->toContain('Observability digest')
        ->and($rendered)->toContain('ERROR')
        ->and($rendered)->toContain('WARNING')
        ->and($rendered)->toContain('badges.sweep.user_failed');
});
