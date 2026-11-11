<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Str;

// Without a backoff, a sub-second network blip to the mail provider uses up
// all of Horizon's tries at once and the mail is lost.
it('gives every queued Mailable a retry backoff', function () {
    $queued = collect(glob(app_path('Mail/*.php')) ?: [])
        ->map(fn (string $path) => 'App\\Mail\\'.Str::before(basename($path), '.php'))
        ->filter(fn (string $class) => is_subclass_of($class, Mailable::class) && is_subclass_of($class, ShouldQueue::class));

    expect($queued)->not->toBeEmpty();

    foreach ($queued as $class) {
        $mailable = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        expect((new SendQueuedMailable($mailable))->backoff())->toBe([10, 60], $class);
    }
});
