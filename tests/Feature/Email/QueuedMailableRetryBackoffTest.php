<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Support\Str;

// A queued Mailable without a retry backoff is retried by Horizon at once
// (config/horizon.php tries=3, no delay), so a sub-second network blip to
// the mail provider burns every attempt and the mail is lost. Every queued
// Mailable must therefore declare a #[Backoff] that spreads its retries.
// This sweep keeps that true for mailables that do not exist yet.

it('gives every queued Mailable a retry backoff', function () {
    $queued = collect(glob(app_path('Mail/*.php')) ?: [])
        ->map(fn (string $path) => 'App\\Mail\\'.Str::before(basename($path), '.php'))
        ->filter(fn (string $class) => is_a($class, Mailable::class, true)
            && is_a($class, ShouldQueue::class, true));

    expect($queued)->not->toBeEmpty();

    $missing = $queued
        ->filter(function (string $class) {
            $attributes = (new ReflectionClass($class))
                ->getAttributes(Backoff::class, ReflectionAttribute::IS_INSTANCEOF);

            if ($attributes === []) {
                return true;
            }

            $backoff = $attributes[0]->newInstance()->backoff;

            return $backoff === [] || $backoff === 0;
        })
        ->values()
        ->all();

    expect($missing)->toBe([], 'Queued Mailables without #[Backoff]: '.implode(', ', $missing));
});
