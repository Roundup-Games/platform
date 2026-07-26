<?php

use App\Livewire\Settings\Show as SettingsShow;
use App\Models\ShortLink;
use App\Models\User;
use App\Services\ShortLinkService;

/**
 * M057/S05/T04 — iCal feed token generation + revocation UI (decision D123).
 *
 * T03 shipped the read path (GET /calendar/{code}); this task ships the write
 * path — the member-facing affordance on the Settings page to generate (and
 * revoke) their personal calendar-feed token. The token reuses the existing
 * ShortLink tokenization (linkable=User, purpose='ical').
 *
 * Contract under test:
 *  - Generate creates a ShortLink (linkable=User, purpose='ical') and surfaces the feed URL.
 *  - Revoke soft-deletes the token (feed returns 404 afterwards).
 *  - Regenerate ROTATES: revokes the old token, issues a new code (at most one active token).
 *  - mount() reflects the current token state (active URL / none / post-revoke).
 *  - The generated token integrates with the ICalFeedController (200 before revoke, 404 after).
 */
describe('calendar feed token — initial state', function () {
    it('shows no feed URL on mount when the user has no token', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->assertSet('calendarFeedUrl', null)
            ->assertSee(__('settings.calendar_feed_generate'))
            ->assertDontSee('calendar-feed-url');
    });

    it('shows the active feed URL on mount when a token exists', function () {
        $user = User::factory()->create();
        $link = ShortLink::factory()->create([
            'linkable_type' => User::class,
            'linkable_id' => $user->id,
            'purpose' => 'ical',
            'url' => route('ical.feed', 'placeholder'),
        ]);

        $expectedUrl = route('ical.feed', $link->code);

        Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->assertSet('calendarFeedUrl', $expectedUrl)
            ->assertSee($expectedUrl);
    });

    it('shows no feed URL on mount when the token was revoked (soft-deleted)', function () {
        $user = User::factory()->create();
        $link = ShortLink::factory()->create([
            'linkable_type' => User::class,
            'linkable_id' => $user->id,
            'purpose' => 'ical',
        ]);
        $link->delete(); // soft-delete = revoked

        Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->assertSet('calendarFeedUrl', null);
    });
});

describe('calendar feed token — generate', function () {
    it('creates a purpose=ical ShortLink linked to the user on generate', function () {
        $user = User::factory()->create();

        expect(ShortLink::where('purpose', 'ical')->count())->toBe(0);

        Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->call('generateCalendarFeedToken')
            ->assertHasNoErrors();

        $token = ShortLink::where('purpose', 'ical')
            ->where('linkable_type', User::class)
            ->where('linkable_id', $user->id)
            ->first();

        expect($token)->not->toBeNull()
            ->and($token->code)->not->toBeEmpty();
    });

    it('surfaces the generated feed URL on the component after generate', function () {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->call('generateCalendarFeedToken');

        $token = ShortLink::where('purpose', 'ical')->where('linkable_id', $user->id)->first();

        $component
            ->assertSet('calendarFeedUrl', route('ical.feed', $token->code))
            ->assertSee(route('ical.feed', $token->code));
    });

    it('renders the generated flash message after generate', function () {
        // The blade shows the flash text when session()->has('calendar_feed_generated');
        // asserting on the rendered output (not the session) tests the user-visible
        // behavior and follows the DataExportTest convention for this component.
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->call('generateCalendarFeedToken')
            ->assertSee(__('settings.calendar_feed_generated_flash'));
    });

    it('generates a token that works with the iCal feed endpoint (integration)', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->call('generateCalendarFeedToken');

        $token = ShortLink::where('purpose', 'ical')->where('linkable_id', $user->id)->first();

        $response = $this->get("/calendar/{$token->code}");

        $response->assertStatus(200);
        expect($response->headers->get('Content-Type'))->toStartWith('text/calendar');
    });
});

describe('calendar feed token — regenerate (rotation)', function () {
    it('revokes the old token and issues a new code on regenerate (at most one active)', function () {
        $user = User::factory()->create();

        // First generation
        $first = Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->call('generateCalendarFeedToken');

        $firstToken = ShortLink::where('purpose', 'ical')->where('linkable_id', $user->id)->first();
        $firstCode = $firstToken->code;

        // Regenerate
        $first->call('generateCalendarFeedToken');

        // Exactly one active (non-soft-deleted) token remains
        $activeCount = ShortLink::where('purpose', 'ical')
            ->where('linkable_id', $user->id)
            ->count();
        expect($activeCount)->toBe(1);

        // The new token has a different code
        $newToken = ShortLink::where('purpose', 'ical')->where('linkable_id', $user->id)->first();
        expect($newToken->code)->not->toBe($firstCode);

        // The old code no longer serves the feed
        $this->get("/calendar/{$firstCode}")->assertNotFound();
    });
});

describe('calendar feed token — revoke', function () {
    it('soft-deletes the token and clears the feed URL on revoke', function () {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->call('generateCalendarFeedToken');

        $component
            ->call('revokeCalendarFeedToken')
            ->assertSet('calendarFeedUrl', null)
            ->assertHasNoErrors();

        // Token is soft-deleted (still in DB with trashed, not in default query)
        expect(ShortLink::withTrashed()->where('purpose', 'ical')->where('linkable_id', $user->id)->count())->toBe(1)
            ->and(ShortLink::where('purpose', 'ical')->where('linkable_id', $user->id)->count())->toBe(0);
    });

    it('renders the revoked flash message after revoke', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->call('generateCalendarFeedToken')
            ->call('revokeCalendarFeedToken')
            ->assertSee(__('settings.calendar_feed_revoked_flash'));
    });

    it('is a safe no-op when no token exists', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->call('revokeCalendarFeedToken')
            ->assertHasNoErrors()
            ->assertSet('calendarFeedUrl', null);

        expect(ShortLink::where('purpose', 'ical')->count())->toBe(0);
    });

    it('makes the feed endpoint return 404 after revocation (integration)', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->call('generateCalendarFeedToken');

        $token = ShortLink::where('purpose', 'ical')->where('linkable_id', $user->id)->first();

        // Feed works before revoke
        $this->get("/calendar/{$token->code}")->assertStatus(200);

        Livewire::actingAs($user)
            ->test(SettingsShow::class)
            ->call('revokeCalendarFeedToken');

        // Feed is gone after revoke
        $this->get("/calendar/{$token->code}")->assertNotFound();
    });
});

describe('calendar feed token — isolation', function () {
    it('does not expose another user token', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        // Alice generates a token
        Livewire::actingAs($alice)
            ->test(SettingsShow::class)
            ->call('generateCalendarFeedToken');

        // Bob's settings page shows no URL
        Livewire::actingAs($bob)
            ->test(SettingsShow::class)
            ->assertSet('calendarFeedUrl', null);

        // Bob still has zero tokens
        expect(ShortLink::where('purpose', 'ical')->where('linkable_id', $bob->id)->count())->toBe(0);
    });

    it('revoke only affects the acting user token', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $aliceToken = app(ShortLinkService::class)->createLink($alice, $alice, [
            'code' => app(ShortLinkService::class)->generateUniqueCode(),
            'url' => route('ical.feed', 'x'),
            'purpose' => 'ical',
        ]);

        // Bob revokes (Bob has no token) — Alice's token must survive
        Livewire::actingAs($bob)
            ->test(SettingsShow::class)
            ->call('revokeCalendarFeedToken');

        expect(ShortLink::where('id', $aliceToken->id)->exists())->toBeTrue();
    });
});
