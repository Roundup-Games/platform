<?php

use App\Enums\AttendanceStatus;
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
