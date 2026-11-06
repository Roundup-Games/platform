<?php

use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

// ── Combined sign-in entrypoint (menu/auth revamp) ─────────────────────
//
// One page serves sign-in and sign-up: GET /login renders the identifier-
// first flow (chooser is client-side localStorage; the server ships the
// no-JS base of both forms), GET /register is a named 301, and POST
// /auth/lookup branches password / oauth-only / new for the client steps.

it('renders the combined sign-in page with both no-JS forms and the branch sections', function () {
    $response = $this->get(route('login'));

    $response->assertOk();

    // Entry + identifier-first copy
    $response->assertSee(__('auth.content_welcome'));
    $response->assertSee(__('auth.content_welcome_sub'));

    // Chooser container exists for the client-side enhancement
    $response->assertSee('id="auth-chooser"', false);

    // No-JS base: the classic login form (password managers pair these)
    $response->assertSee('autocomplete="current-password"', false);
    $response->assertSee(__('auth.content_forgot_your_password'));

    // No-JS base: the sign-up branch rides a <details> — reachable without JS
    $response->assertSee('id="auth-signup-details"', false);
    $response->assertSee('autocomplete="new-password"', false);
    $response->assertSee(__('auth.action_create_account'));

    // Terms/CoC binding travels with the sign-up branch (CodeOfConduct canon)
    $response->assertSee(__('safety.content_signup_agreement_prefix'));
    $response->assertSee(route('safety.code-of-conduct'));

    // OAuth-only branch section is server-rendered (hidden) for the client
    $response->assertSee('id="auth-oauth-only"', false);
    $response->assertSee(__('auth.action_set_password_link'));
});

it('permanently redirects register to the combined page in every locale', function (string $locale) {
    $this->get("/{$locale}/register")
        ->assertStatus(301)
        ->assertRedirect("/{$locale}/login");
})->with(['en', 'de']);

it('keeps route(register) resolvable as a redirect target for invitation links', function () {
    // OverflowRouter/ParticipantService invitation emails carry
    // route('register') — the URL must still resolve (now via redirect).
    expect(route('register'))->toEndWith('/en/register');
});

// ── POST /auth/lookup ──────────────────────────────────────────────────

it('answers new for an unknown email', function () {
    $this->postJson(route('auth.lookup'), ['email' => 'fresh@example.com'])
        ->assertOk()
        ->assertExactJson(['status' => 'new']);
});

it('answers password for an account with a password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('Sup3rSecret!pass'),
    ]);

    $this->postJson(route('auth.lookup'), ['email' => $user->email])
        ->assertOk()
        ->assertExactJson(['status' => 'password']);
});

it('answers oauth with the provider for an OAuth-only account', function () {
    $user = User::factory()->create(['password' => null]);
    LinkedAccount::factory()->for($user)->create([
        'provider' => 'discord',
    ]);

    $this->postJson(route('auth.lookup'), ['email' => $user->email])
        ->assertOk()
        ->assertExactJson(['status' => 'oauth', 'provider' => 'discord']);
});

it('answers oauth without a provider for a passwordless account with no links', function () {
    // Defensive shape: a password-null user without linked accounts cannot
    // take the password branch (dead credentials error) — the client routes
    // them to the set-password link instead.
    $user = User::factory()->create(['password' => null]);

    $this->postJson(route('auth.lookup'), ['email' => $user->email])
        ->assertOk()
        ->assertExactJson(['status' => 'oauth', 'provider' => null]);
});

it('rejects an invalid email with validation errors', function () {
    $this->postJson(route('auth.lookup'), ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('rate limits the lookup endpoint beyond ten attempts per email', function () {
    // The limiter keys by IP+email AND by bare IP (spread-probe ceiling) —
    // clear both so this test stays hermetic within a shared process.
    $compositeKey = 'lookup:'.sha1('127.0.0.1|'.'harvest@example.com');
    RateLimiter::clear($compositeKey);
    RateLimiter::clear('lookup-ip:127.0.0.1');

    try {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('auth.lookup'), ['email' => 'harvest@example.com'])->assertOk();
        }

        $this->postJson(route('auth.lookup'), ['email' => 'harvest@example.com'])
            ->assertStatus(429);
    } finally {
        RateLimiter::clear($compositeKey);
        RateLimiter::clear('lookup-ip:127.0.0.1');
    }
});

it('ejects authenticated users from the lookup endpoint', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('auth.lookup'), ['email' => 'any@example.com'])
        ->assertRedirect();
});

// ── Remembered-account stamp data ─────────────────────────────────────

// ── Share-link intent (auth-wall → sign-up → original destination) ────

it('keeps the intended destination alive through email sign-up', function () {
    // Guest clicks a shared game link, hits the auth wall, and signs up:
    // onboarding is the waypoint, url.intended must survive until then.
    session(['url.intended' => '/en/games/some-shared-game']);

    $this->post('/en/register', [
        'name' => 'Share Link Visitor',
        'email' => 'share-link@example.com',
        'password' => 'Sup3rSecret!pass',
    ])->assertRedirect(route('onboarding.index'));

    expect(session('url.intended'))->toBe('/en/games/some-shared-game');
});

it('offers a locale switcher on the combined sign-in page', function () {
    // Share links are locale-prefixed — a visitor can land on the wrong
    // language. The guest layout must carry the segmented switcher.
    $this->get('/de/login')
        ->assertOk()
        ->assertSee(route('locale.switch', ['locale' => 'en', 'redirect' => '/en/login']), false)
        ->assertSee(__('common.content_language'));
});

it('marks the sign-in method in the session after a password login', function () {
    $user = User::factory()->create([
        'password' => Hash::make('Sup3rSecret!pass'),
        'profile_complete' => true,
    ]);

    $this->post('/en/login', [
        'email' => $user->email,
        'password' => 'Sup3rSecret!pass',
    ])->assertRedirect(route('dashboard', absolute: false));

    expect(session('last_login_method'))->toBe('password');
});
