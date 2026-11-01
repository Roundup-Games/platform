<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| demo:seed / demo:teardown environment guards
|--------------------------------------------------------------------------
|
| These refuse BEFORE anything is written, so they get their own file with
| no seeding beforeEach — a full demo:seed (~1.4k queries) per guard test
| was pure waste (2026-10-31 harness performance report). The guard list
| and the nothing-written invariant are pinned exactly as before.
|
| The AppServiceProvider registration gate only excludes exactly
| "production"; the in-command guard is the layer that must refuse the
| other prod-adjacent names (prod, live, staging) even under direct
| instantiation — pin it so the guard list cannot regress silently.
*/

it('refuses to seed in production-ish environments (defense-in-depth under the registration gate)', function () {
    // app()->environment() resolves the container's 'env' binding, not
    // live config('app.env') — rebind it to the prod-adjacent name.
    app()->instance('env', 'staging');

    $this->artisan('demo:seed', ['--users' => 5, '--gms' => 1])
        ->expectsOutput('demo:seed is a development tool and is disabled in production.')
        ->assertFailed();

    expect(User::query()->where('email', 'like', '%@demo.roundup.games')->doesntExist())
        ->toBeTrue();
});

it('refuses to tear down in production-ish environments too', function () {
    app()->instance('env', 'prod');

    $this->artisan('demo:teardown')
        ->expectsOutput('demo:teardown is a development tool and is disabled in production.')
        ->assertFailed();
});
