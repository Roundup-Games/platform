<?php

use App\Http\Controllers\InviteOptoutController;
use App\Models\SuppressedInviteEmail;
use Illuminate\Http\Request;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

//
// Invite opt-out privacy path smoke tests (M058/S06).
//
// InviteOptoutController is the privacy-critical path for unsubscribing from
// email invitations by email hash — it had ZERO tests. Two-step GET-then-POST
// flow prevents email security scanners from triggering false suppression.
// Suppression is idempotent and reversible (row removal re-enables).
//
// These tests exercise the full HTTP layer (both GET render and POST confirm).
// They previously could not: InviteOptoutController lived under the {locale}
// URL prefix, but its methods were declared confirm(Request, $emailHash) — and
// classic controllers bind route parameters POSITIONALLY after type-hinted
// dependencies, so $emailHash received the 'en' locale, the regex always failed,
// and every visitor saw the 'invalid' view (suppression never recorded). The
// controller now declares the {locale} parameter first, and these tests pin the
// real render + persistence behavior so it cannot regress.
//

it('renders the opt-out CONFIRMATION page (not the invalid page) for a valid hash', function () {
    $hash = hash('sha256', 'invitee@example.com');

    get("/en/invite-optout/{$hash}")
        ->assertSuccessful()
        ->assertSee(__('invite_optout.title_confirm'))   // confirm step rendered
        ->assertDontSee(__('invite_optout.title_invalid')); // not the invalid view
})->group('smoke');

it('rejects an invalid hash format with a 404 (route constraint)', function () {
    // The route constrains {emailHash} to a SHA-256 hex pattern, so a malformed
    // hash never reaches the controller — it 404s. The controller's regex check
    // is defense-in-depth for when the constraint is bypassed (covered below).
    get('/en/invite-optout/not-a-real-hash')->assertNotFound();
})->group('smoke');

it('confirms the opt-out over HTTP and persists the suppression idempotently', function () {
    $hash = hash('sha256', 'member@example.com');

    expect(SuppressedInviteEmail::where('email_hash', $hash)->exists())->toBeFalse();

    post("/en/invite-optout/{$hash}")
        ->assertSuccessful()
        ->assertSee(__('invite_optout.title_confirmed'));

    expect(SuppressedInviteEmail::where('email_hash', $hash)->exists())->toBeTrue();

    // Idempotent: a second confirm does not create a duplicate row.
    post("/en/invite-optout/{$hash}")->assertSuccessful();
    expect(SuppressedInviteEmail::where('email_hash', $hash)->count())->toBe(1);
})->group('smoke');

it('rejects confirmation with an invalid hash format (no suppression persisted)', function () {
    // The route constraint 404s on non-hex input, so this exercises the
    // controller's own regex defense directly (defense-in-depth).
    $controller = app(InviteOptoutController::class);
    $request = Request::create('/en/invite-optout/bad-hash', 'POST');

    $controller->confirm($request, 'en', 'bad-hash');

    expect(SuppressedInviteEmail::count())->toBe(0);
})->group('smoke');

it('allows re-enabling invitations by removing the suppression', function () {
    $hash = hash('sha256', 'returning@example.com');
    SuppressedInviteEmail::create(['email_hash' => $hash, 'created_at' => now()]);

    expect(SuppressedInviteEmail::where('email_hash', $hash)->exists())->toBeTrue();

    // Removal re-enables (admin/DB operation; we verify the suppression gate
    // is reversible — the controller does not expose a re-enable route).
    SuppressedInviteEmail::where('email_hash', $hash)->delete();

    expect(SuppressedInviteEmail::where('email_hash', $hash)->exists())->toBeFalse();
})->group('smoke');
