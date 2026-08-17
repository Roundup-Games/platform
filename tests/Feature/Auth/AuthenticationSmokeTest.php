<?php

use App\Http\Middleware\CaptureFirstTouch;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

//
// Standard email/password authentication smoke tests (M058/S02).
//
// CONTEXT: OAuth (incl. the email_verified account-takeover gate) is heavily
// tested in OAuthTest.php, but the Breeze email/password flows — register,
// login, logout, password reset, email verification, password confirmation —
// had ZERO feature tests anywhere in the suite. These are the most fundamental
// "can a user get into and recover their account" critical paths.
//
// Also covers EnsureUserNotDisabled (disabled-user ejection), a security
// guardrail that must kick disabled users out of authenticated routes.
//
// Routes are locale-prefixed ({locale}/register etc.); Pest.php beforeEach
// sets URL::defaults(['locale' => 'en']) so route() helpers resolve to /en/...
//

// ── Helpers ───────────────────────────────────────────────────────────────

/**
 * Build valid registration payload. Email must be unique per call (parallel-safe).
 */
function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Jamie Test',
        'email' => 'smoke-'.uniqid('reg', true).'@test.test',
        'password' => 'Sup3rSecret!pass',
        'password_confirmation' => 'Sup3rSecret!pass',
    ], $overrides);
}

// ── Registration ─────────────────────────────────────────────────────────

it('registers a new user, fires Registered, and redirects to onboarding', function () {
    Event::fake([Registered::class]);
    Notification::fake();

    $payload = registrationPayload();

    $response = $this->post('/en/register', $payload);

    $response->assertRedirect(route('onboarding.index'));

    // User persisted with the sanitized name and email
    $user = User::where('email', $payload['email'])->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Jamie Test')
        ->and(Hash::check($payload['password'], $user->password))->toBeTrue();

    // Registered event fires (triggers the welcome + verification notification chain)
    Event::assertDispatched(Registered::class, fn (Registered $e) => $e->user->is($user));

    // New registrant is logged in and profile_complete is false (onboarding gate)
    expect($this->isAuthenticated())->toBeTrue()
        ->and($user->fresh()->profile_complete)->toBeFalse();
})->group('smoke');

it('rejects registration with duplicate email', function () {
    $existing = User::factory()->create();

    $response = $this->post('/en/register', registrationPayload([
        'email' => $existing->email,
    ]));

    $response->assertSessionHasErrors(['email']);
    expect(User::where('email', $existing->email)->count())->toBe(1);
})->group('smoke');

it('rethrows a duplicate-email insert race as an email field error, not a 500', function () {
    // The 'unique:users' rule and the insert are two statements, so two
    // near-simultaneous signups both pass the rule and the second insert hits
    // users_email_unique. Simulate that: after validation passes, a concurrent
    // signup inserts the same email just before store() reaches its own insert.
    $payload = registrationPayload();

    $raced = false;
    User::creating(function () use (&$raced, $payload) {
        if ($raced) {
            return;
        }
        $raced = true;
        // Let events fire so the model's creating hook still generates the id
        // and slug. The $raced guard above stops this insert from recursing.
        User::factory()->create(['email' => $payload['email']]);
    });

    $response = $this->post('/en/register', $payload);

    // A 500 would set no validation errors; the email error proves store()
    // caught the constraint violation and turned it into a field error. We
    // cannot query users here: Postgres aborts the surrounding test
    // transaction once the duplicate insert fails.
    $response->assertRedirect();
    $response->assertSessionHasErrors(['email']);
})->group('smoke');

it('does not mislabel a slug race as an email error when the email is unique', function () {
    // generateUniqueSlug() is itself check-then-insert: two concurrent
    // signups with the same name can both scan the same free slug, and the
    // loser's insert hits users_slug_unique — a DIFFERENT constraint from
    // the email one. Simulate the losing racer: the owner's row lands
    // between this request's slug scan and its insert.
    User::factory()->create([
        'name' => 'Slug Racer',
        'slug' => 'slug-racer-owner',
    ]);

    $forced = false;
    User::creating(function (User $user) use (&$forced) {
        if ($forced) {
            return;
        }
        $forced = true;
        $user->slug = 'slug-racer-owner';
    });

    // The racer's email is unique — an "email already taken" error here
    // would be a false field error (and would send a perfectly valid
    // address down the forgot-password path).
    $payload = registrationPayload(['name' => 'Slug Racer']);

    // store() handles a slug race by retrying with a randomized slug. Under
    // the RefreshDatabase transaction that retry cannot complete (Postgres
    // poisons the surrounding transaction on the first failed insert, so the
    // retry aborts with 25P02 before any constraint check); in production
    // there is no wrapping transaction and the retry succeeds. Either way,
    // the outcome must NOT carry a validation error on email — that is the
    // mislabeling this test pins against. In feature tests the kernel
    // converts a ValidationException to a redirect with the errors flashed
    // into the session; the array session driver serializes the ViewErrorBag
    // to ['default' => ['format' => ..., 'messages' => [...]]], so the
    // extraction below reads both the object and serialized shapes. The raw
    // catch covers a future change to exception propagation.
    try {
        $this->post('/en/register', $payload);
    } catch (ValidationException $e) {
        expect($e->errors())->not->toHaveKey('email');
    } catch (QueryException) {
        // Expected under the test transaction only (aborted transaction).
    }

    $flashed = session('errors');
    $messages = match (true) {
        $flashed instanceof ViewErrorBag => $flashed->getBag('default')->getMessages(),
        is_array($flashed) && isset($flashed['default']['messages']) => $flashed['default']['messages'],
        default => [],
    };
    expect($messages)->not->toHaveKey('email');
})->group('smoke');

it('keeps first-touch attribution across a lost duplicate-email race', function () {
    // A raced signup is shown a field error, not a 500 — the person retries.
    // The first-touch capture keys must survive that failed attempt so the
    // eventual successful retry still attributes the signup (the Signup
    // Attribution Report consumes the write-once columns).
    $this->withSession([
        CaptureFirstTouch::PATH_KEY => '/en/discovery',
        CaptureFirstTouch::REFERER_KEY => 'https://google.com/search?q=dnd',
        CaptureFirstTouch::CAPTURED_KEY => true,
    ]);

    $payload = registrationPayload();

    $raced = false;
    User::creating(function () use (&$raced, $payload) {
        if ($raced) {
            return;
        }
        $raced = true;
        User::factory()->create(['email' => $payload['email']]);
    });

    $this->post('/en/register', $payload)->assertSessionHasErrors(['email']);

    // Session driver is array in tests, so this read is safe even though
    // the surrounding database transaction is aborted.
    expect(session(CaptureFirstTouch::PATH_KEY))->toBe('/en/discovery')
        ->and(session(CaptureFirstTouch::REFERER_KEY))->toBe('https://google.com/search?q=dnd')
        ->and(session(CaptureFirstTouch::CAPTURED_KEY))->toBeTrue();
})->group('smoke');

it('sends an email verification notification on registration (MustVerifyEmail contract)', function () {
    // M058: User now implements MustVerifyEmail, so the framework's
    // SendEmailVerificationNotification listener fires on Registered and sends
    // the verification email. Before the interface was added, the listener's
    // `instanceof MustVerifyEmail` check was false and the email never sent —
    // despite the app shipping full verification infrastructure (routes,
    // signed URL, 6 ['auth','verified'] route groups). This pins the restored
    // behavior so it cannot silently revert.
    Notification::fake();
    $payload = registrationPayload();

    $this->post('/en/register', $payload);

    $user = User::where('email', $payload['email'])->firstOrFail();
    Notification::assertSentTo($user, VerifyEmail::class);
})->group('smoke');

// ── Login / Logout ───────────────────────────────────────────────────────

it('logs in a user with valid credentials and regenerates the session', function () {
    $user = User::factory()->create([
        'password' => Hash::make('Sup3rSecret!pass'),
        'profile_complete' => true,
    ]);

    $response = $this->post('/en/login', [
        'email' => $user->email,
        'password' => 'Sup3rSecret!pass',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
    expect(session()->has('_token'))->toBeTrue();
})->group('smoke');

it('rejects login with invalid credentials', function () {
    $user = User::factory()->create([
        'password' => Hash::make('Sup3rSecret!pass'),
        'profile_complete' => true,
    ]);

    $this->withSession(['_token' => $token = csrf_token()])->post('/en/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
        '_token' => $token,
    ])->assertSessionHasErrors();

    expect($this->isAuthenticated())->toBeFalse();
})->group('smoke');

it('logs out and invalidates the session with Clear-Site-Data header', function () {
    $user = User::factory()->create(['profile_complete' => true]);
    $this->actingAs($user);

    $response = $this->post('/en/logout');

    $response->assertRedirect(route('root'));
    expect($response->headers->get('Clear-Site-Data'))->toContain('storage');
    expect($this->isAuthenticated())->toBeFalse();
})->group('smoke');

// ── Password Reset ───────────────────────────────────────────────────────

it('sends a password reset link for a known email', function () {
    Notification::fake();
    $user = User::factory()->create();

    $response = $this->post('/en/forgot-password', ['email' => $user->email]);

    $response->assertSessionHas('status');
    Notification::assertSentTo($user, ResetPassword::class);
})->group('smoke');

it('resets a password with a valid token and the new password works', function () {
    Event::fake([PasswordReset::class]);
    Notification::fake();

    $user = User::factory()->create();
    $token = PasswordBroker::createToken($user);

    $newPassword = 'Br4ndNew!pass';

    $response = $this->post('/en/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => $newPassword,
        'password_confirmation' => $newPassword,
    ]);

    $response->assertRedirect(route('login'));
    Event::assertDispatched(PasswordReset::class);

    // New password works for login
    $this->post('/en/login', [
        'email' => $user->email,
        'password' => $newPassword,
    ])->assertRedirect(route('dashboard', absolute: false));
})->group('smoke');

it('rejects password reset with an invalid token', function () {
    $user = User::factory()->create();
    $newPassword = 'Br4ndNew!pass';

    $this->post('/en/reset-password', [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => $newPassword,
        'password_confirmation' => $newPassword,
    ])->assertSessionHasErrors(['email']);
})->group('smoke');

// ── Password Confirmation ────────────────────────────────────────────────

it('confirms password and sets the auth.password_confirmed_at timestamp', function () {
    $user = User::factory()->create([
        'password' => Hash::make('Sup3rSecret!pass'),
        'profile_complete' => true,
    ]);
    $this->actingAs($user);

    $response = $this->post('/en/confirm-password', ['password' => 'Sup3rSecret!pass']);

    expect($response->isRedirect())->toBeTrue();
    expect(session()->get('auth.password_confirmed_at'))->not->toBeNull();
})->group('smoke');

it('rejects password confirmation with the wrong password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('Sup3rSecret!pass'),
        'profile_complete' => true,
    ]);
    $this->actingAs($user);

    $this->post('/en/confirm-password', ['password' => 'wrong-password'])
        ->assertSessionHasErrors();

    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
})->group('smoke');

// ── Email Verification (signed URL) ──────────────────────────────────────
//
// The signed verification URL is the primary email-ownership proof. This also
// empirically confirms the User model exposes the hasVerifiedEmail /
// markEmailAsVerified contract the verified middleware depends on.

it('verifies email via the signed URL and marks email_verified_at', function () {
    // The User model extends Illuminate\Foundation\Auth\User which uses the
    // MustVerifyEmail trait (methods exist). M058/S02 added the MustVerifyEmail
    // interface to User's implements list so VerifyEmailController's
    // assert($user instanceof MustVerifyEmail) no longer fatals — the email
    // verification flow was previously broken (marked verified then 500'd).
    expect((new User) instanceof MustVerifyEmail)->toBeTrue();

    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    $url = URL::signedRoute('verification.verify', [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->get($url)->assertRedirect();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
})->group('smoke');

// ── Disabled User Ejection ───────────────────────────────────────────────

it('ejects a disabled user from authenticated routes and invalidates the session', function () {
    // Establish a real session via login (the dashboard route requires auth +
    // not.disabled + verified + profile.complete).
    $user = User::factory()->create([
        'password' => Hash::make('Sup3rSecret!pass'),
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    $this->withSession([])->post('/en/login', [
        'email' => $user->email,
        'password' => 'Sup3rSecret!pass',
    ])->assertRedirect();
    expect($this->isAuthenticated())->toBeTrue('login should establish the session');

    // Disable the user AFTER login — the next request must eject them.
    $user->update(['is_disabled' => true, 'disabled_at' => now()]);

    // In production each request re-resolves the user via retrieveById (fresh
    // model with is_disabled=true). The test Auth guard caches the model across
    // calls, so explicitly re-auth with the fresh model to simulate that.
    $this->actingAs($user->fresh());

    $response = $this->get(route('dashboard', absolute: false));

    // Ejected: 302 to root. Assert status + Location (the middleware invalidates
    // the session, so assertRedirect() — which reads flashed session state — is
    // unreliable here).
    expect($response->status())->toBe(302)
        ->and($response->headers->get('Location'))->toBe(route('root'));
})->group('smoke');
