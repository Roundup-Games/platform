<?php

use App\Enums\AttendanceStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\PostHogAnalytics;
use App\Services\PostHogClient;
use App\Services\PostHogConsentChecker;
use Illuminate\Support\Facades\Config;
use Tests\Helpers\TestablePostHogClient;

beforeEach(function () {
    Config::set('posthog.enabled', true);
    Config::set('posthog.api_key', 'phc_test_key');

    $this->posthogClient = new TestablePostHogClient;
    $this->app->instance(PostHogClient::class, $this->posthogClient);

    // Consent granted by default; tests that need it denied call denyAnalyticsConsent().
    grantAnalyticsConsent();
});

// Rebind a consent checker returning the given verdict. Matches the pattern used
// in PostHogMiddlewareTest to avoid Mockery closure-binding quirks.
function grantAnalyticsConsent()
{
    $checker = test()->mock(PostHogConsentChecker::class);
    $checker->shouldReceive('hasAnalyticsConsent')->andReturn(true);
    app()->instance(PostHogConsentChecker::class, $checker);
}

function denyAnalyticsConsent()
{
    $checker = test()->mock(PostHogConsentChecker::class);
    $checker->shouldReceive('hasAnalyticsConsent')->andReturn(false);
    app()->instance(PostHogConsentChecker::class, $checker);
}

describe('PostHogAnalytics::capture — consent gating', function () {
    test('captures when PostHog enabled and consent granted', function () {
        $user = User::factory()->create();

        app(PostHogAnalytics::class)->capture($user, 'user.signed_up', ['signup_method' => 'email']);

        expect($this->posthogClient->capturedCalls)->toHaveCount(1)
            ->and($this->posthogClient->capturedCalls[0]['event'])->toBe('user.signed_up')
            ->and($this->posthogClient->capturedCalls[0]['distinctId'])->toBe((string) $user->id);
    });

    test('skips capture when analytics consent is absent', function () {
        denyAnalyticsConsent();
        $user = User::factory()->create();

        app(PostHogAnalytics::class)->capture($user, 'user.signed_up');

        expect($this->posthogClient->capturedCalls)->toHaveCount(0);
    });

    test('skips capture when PostHog is disabled', function () {
        $this->posthogClient->setEnabled(false);
        $user = User::factory()->create();

        app(PostHogAnalytics::class)->capture($user, 'user.signed_up');

        expect($this->posthogClient->capturedCalls)->toHaveCount(0);
    });
});

describe('PostHogAnalytics::capture — pseudonymization', function () {
    test('distinctId is the opaque user id, never name or email', function () {
        $user = User::factory()->create(['name' => 'Real Name', 'email' => 'real@example.com']);

        app(PostHogAnalytics::class)->capture($user, 'onboarding.completed', ['game_systems_selected_count' => 3]);

        $payload = $this->posthogClient->capturedCalls[0];
        expect($payload['distinctId'])->toBe((string) $user->id)
            ->and(json_encode($payload))->not->toContain('real@example.com')
            ->and(json_encode($payload))->not->toContain('Real Name');
    });
});

describe('PostHogAnalytics::captureAttendanceOutcome', function () {
    test('captures the resolved outcome with reliability properties', function () {
        $user = User::factory()->create();
        $system = GameSystem::factory()->create(['name' => 'D&D 5e']);
        $game = Game::factory()->create([
            'owner_id' => $user->id,
            'location' => ['type' => 'online'],
        ]);
        $game->gameSystems()->attach($system->id);
        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
        ]);

        app(PostHogAnalytics::class)->captureAttendanceOutcome($participant, AttendanceStatus::NoShow, 'consensus');

        // Other models carry observers that forward activity events to PostHog on
        // create; filter to the attendance event we care about.
        $attendance = collect($this->posthogClient->capturedCalls)
            ->first(fn (array $c) => ($c['event'] ?? null) === 'attendance.recorded');

        expect($attendance)->not->toBeNull();
        expect($attendance['distinctId'])->toBe((string) $user->id)
            ->and($attendance['properties']['attendance_status'])->toBe('no_show')
            ->and($attendance['properties']['resolution_context'])->toBe('consensus')
            ->and($attendance['properties']['game_system'])->toBe('D&D 5e')
            ->and($attendance['properties']['is_online'])->toBeTrue();
    });

    test('is gated behind analytics consent', function () {
        denyAnalyticsConsent();
        $participant = GameParticipant::factory()->create();

        app(PostHogAnalytics::class)->captureAttendanceOutcome($participant, AttendanceStatus::Attended, 'report');

        expect($this->posthogClient->capturedCalls)->toHaveCount(0);
    });

    test('skips participants without a user account (invitee-by-email)', function () {
        $participant = GameParticipant::factory()->create(['user_id' => null]);

        app(PostHogAnalytics::class)->captureAttendanceOutcome($participant, AttendanceStatus::Attended, 'report');

        $attendance = collect($this->posthogClient->capturedCalls)
            ->filter(fn (array $c) => ($c['event'] ?? null) === 'attendance.recorded');
        expect($attendance)->toHaveCount(0);
    });
});

describe('PostHogAnalytics — webhook consent fallback', function () {
    test('captures when cookie consent is absent but persisted flag is true', function () {
        // Simulates a Paddle webhook: no cookie_consent cookie, but the user
        // previously consented and the middleware persisted analytics_consent=true.
        denyAnalyticsConsent();
        $user = User::factory()->create(['analytics_consent' => true]);

        app(PostHogAnalytics::class)->capture($user, 'subscription.started', ['status' => 'active']);

        $sub = collect($this->posthogClient->capturedCalls)
            ->first(fn (array $c) => ($c['event'] ?? null) === 'subscription.started');
        expect($sub)->not->toBeNull()
            ->and($sub['distinctId'])->toBe((string) $user->id);
    });

    test('skips when both cookie and persisted flag are absent', function () {
        denyAnalyticsConsent();
        $user = User::factory()->create(['analytics_consent' => false]);

        app(PostHogAnalytics::class)->capture($user, 'subscription.started');

        $sub = collect($this->posthogClient->capturedCalls)
            ->filter(fn (array $c) => ($c['event'] ?? null) === 'subscription.started');
        expect($sub)->toHaveCount(0);
    });
});

describe('PostHogAnalytics::identifyFirstTouch', function () {
    test('sets first_touch referer domain and entry path as $set_once', function () {
        $user = User::factory()->create();

        app(PostHogAnalytics::class)->identifyFirstTouch(
            $user,
            'https://google.com/search?q=board+games',
            'en/discovery',
        );

        expect($this->posthogClient->identifyCalls)->toHaveCount(1);
        $payload = $this->posthogClient->identifyCalls[0];
        $setOnce = $payload['properties']['$set_once'] ?? [];
        expect($setOnce['first_touch_referer_domain'])->toBe('google.com')
            ->and($setOnce['first_touch_entry_path'])->toBe('en/discovery');
    });

    test('reduces referer to hostname only (strips query/UTM)', function () {
        $user = User::factory()->create();

        app(PostHogAnalytics::class)->identifyFirstTouch(
            $user,
            'https://google.com/search?q=test&utm_source=mail&uid=12345',
            'en/register',
        );

        $setOnce = $this->posthogClient->identifyCalls[0]['properties']['$set_once'];
        expect($setOnce['first_touch_referer_domain'])->toBe('google.com')
            ->and($setOnce)->not->toHaveKey('utm_source');
    });

    test('is consent-gated', function () {
        denyAnalyticsConsent();
        $user = User::factory()->create(['analytics_consent' => false]);

        app(PostHogAnalytics::class)->identifyFirstTouch($user, 'https://google.com', '/en');

        expect($this->posthogClient->identifyCalls)->toHaveCount(0);
    });
});

describe('PostHogAnalytics::identifyFirstTouch — SEO content detection', function () {
    test('detects game content from intended URL via session', function () {
        $user = User::factory()->create();
        session(['url.intended' => 'https://roundup.games/en/games/apply/dnd-5e-one-shot']);

        app(PostHogAnalytics::class)->identifyFirstTouch($user, null, 'en/register');

        $setOnce = $this->posthogClient->identifyCalls[0]['properties']['$set_once'];
        expect($setOnce['signup_content_type'])->toBe('game')
            ->and($setOnce['signup_content_slug'])->toBe('dnd-5e-one-shot');
    });

    test('detects campaign content from entry path when no intended URL', function () {
        $user = User::factory()->create();
        session()->flush();

        app(PostHogAnalytics::class)->identifyFirstTouch($user, null, 'en/campaigns/curse-of-strahd');

        $setOnce = $this->posthogClient->identifyCalls[0]['properties']['$set_once'];
        expect($setOnce['signup_content_type'])->toBe('campaign')
            ->and($setOnce['signup_content_slug'])->toBe('curse-of-strahd');
    });

    test('leaves content null for generic pages', function () {
        $user = User::factory()->create();
        session()->flush();

        app(PostHogAnalytics::class)->identifyFirstTouch($user, 'https://google.com', 'en/register');

        $setOnce = $this->posthogClient->identifyCalls[0]['properties']['$set_once'];
        expect($setOnce)->not->toHaveKey('signup_content_type')
            ->and($setOnce)->not->toHaveKey('signup_content_slug');
    });
});

describe('PostHogAnalytics::captureParticipantTransition', function () {
    test('captures application.approved with entity enrichment', function () {
        $user = User::factory()->create();
        $system = GameSystem::factory()->create(['name' => 'Pathfinder']);
        $game = Game::factory()->create(['owner_id' => $user->id]);
        $game->gameSystems()->attach($system->id);
        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
        ]);

        app(PostHogAnalytics::class)->captureParticipantTransition(
            $participant, $game, 'application.approved', ['approved_by' => 'host-uuid'],
        );

        $event = collect($this->posthogClient->capturedCalls)
            ->first(fn (array $c) => ($c['event'] ?? null) === 'application.approved');
        expect($event)->not->toBeNull()
            ->and($event['distinctId'])->toBe((string) $user->id)
            ->and($event['properties']['entity_type'])->toBe('game')
            ->and($event['properties']['game_system'])->toBe('Pathfinder')
            ->and($event['properties']['approved_by'])->toBe('host-uuid');
    });

    test('captures application.rejected for campaigns', function () {
        $user = User::factory()->create();
        $system = GameSystem::factory()->create(['name' => 'D&D 5e']);
        $campaign = Campaign::factory()->create(['owner_id' => $user->id]);
        $campaign->gameSystems()->attach($system->id);
        $participant = CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
        ]);

        app(PostHogAnalytics::class)->captureParticipantTransition(
            $participant, $campaign, 'application.rejected',
        );

        $event = collect($this->posthogClient->capturedCalls)
            ->first(fn (array $c) => ($c['event'] ?? null) === 'application.rejected');
        expect($event)->not->toBeNull()
            ->and($event['properties']['entity_type'])->toBe('campaign');
    });

    test('is gated behind consent', function () {
        denyAnalyticsConsent();
        $user = User::factory()->create(['analytics_consent' => false]);
        $game = Game::factory()->create();
        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
        ]);

        app(PostHogAnalytics::class)->captureParticipantTransition(
            $participant, $game, 'participant.removed',
        );

        $event = collect($this->posthogClient->capturedCalls)
            ->filter(fn (array $c) => ($c['event'] ?? null) === 'participant.removed');
        expect($event)->toHaveCount(0);
    });
});
