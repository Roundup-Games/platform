<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Traits\CapturesLogRecords;

//
// Consent-independent client runtime-error beacon (POST /client-errors).
// Guest-accessible BY DESIGN: posthog-js only captures exceptions for
// consented sessions, so this endpoint is the only visibility into runtime
// errors from non-consenting users. CSRF is bypassed in tests via
// PreventRequestForgery::runningUnitTests(); in production the beacon sends
// the X-CSRF-TOKEN header from the layout meta tag.
//
// The throttle budget is per-IP in tests (every request originates from
// 127.0.0.1 and shares the array-cache bucket for the whole worker), so the
// flood test clears the bucket before AND after to avoid leaking budget
// into later tests (TimezoneBeaconTest precedent, keyed differently there).
//

uses(CapturesLogRecords::class);

beforeEach(function () {
    $this->captureLogRecords();

    RateLimiter::clear('client-errors:127.0.0.1');
});

afterEach(function () {
    RateLimiter::clear('client-errors:127.0.0.1');
});

function clientErrorPayload(): array
{
    return [
        'message' => 'Uncaught TypeError: Cannot read properties of null',
        'file' => 'https://roundup.games/assets/app-abc123.js',
        'line' => 42,
        'col' => 17,
        'digest' => '3f2a91c0',
        'page' => '/de/games',
    ];
}

it('accepts a valid runtime-error report from a guest and logs it', function () {
    $this->postJson(route('client-errors.report'), clientErrorPayload())
        ->assertNoContent();

    $record = $this->logRecord('js.runtime_error');

    expect($record)->not->toBeNull()
        ->and($record['level_name'])->toBe('ERROR')
        ->and($record['context']['message'])->toBe(clientErrorPayload()['message'])
        ->and($record['context']['file'])->toBe(clientErrorPayload()['file'])
        ->and($record['context']['page'])->toBe('/de/games')
        ->and($record['context']['ip_hash'])->toMatch('/^[0-9a-f]{64}$/');
});

it('never logs the full stack or user identifiers — only the digest fingerprint', function () {
    $this->postJson(route('client-errors.report'), clientErrorPayload())
        ->assertNoContent();

    $context = $this->logRecord('js.runtime_error')['context'];

    expect($context)->not->toHaveKey('stack')
        ->and($context)->not->toHaveKey('stack_digest')
        ->and($context)->not->toHaveKey('ip')
        ->and($context)->not->toHaveKey('user_id')
        ->and($context['digest'])->toBe('3f2a91c0');
});

it('rejects junk payloads with 422 and logs nothing', function () {
    $this->postJson(route('client-errors.report'), [])
        ->assertStatus(422);

    $this->postJson(route('client-errors.report'), array_merge(clientErrorPayload(), [
        'line' => -1,
    ]))->assertStatus(422);

    expect($this->logRecords())->toBeEmpty();
});

it('requires no authentication and also accepts signed-in members', function () {
    $user = User::factory()->create();

    $this->postJson(route('client-errors.report'), clientErrorPayload())
        ->assertNoContent();

    $this->actingAs($user)
        ->postJson(route('client-errors.report'), clientErrorPayload())
        ->assertNoContent();

    expect($this->logRecord('js.runtime_error'))->not->toBeNull();
});

it('throttles report floods within the same minute', function () {
    for ($report = 0; $report < 10; $report++) {
        $this->postJson(route('client-errors.report'), clientErrorPayload())
            ->assertNoContent();
    }

    $this->postJson(route('client-errors.report'), clientErrorPayload())
        ->assertStatus(429);
});
