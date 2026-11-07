<?php

use PostHog\HttpClient;
use PostHog\HttpResponse;
use Tests\Helpers\FakePostHogHttpClient;

/*
 * Pins the actual surface of FakePostHogHttpClient — the offline transport
 * behind every PostHog test (see PostHogEventCaptureTest).
 *
 * Deliberate gap: the fake simulates NOTHING of the real LibCurl consumer's
 * retry/backoff behavior. Avoiding that behavior is its entire purpose (the
 * 2026-11-02 harness runtime report traced 1-20s network-state-dependent
 * tests to the real consumer). Do not add retry simulation here — tests
 * that need to reason about retry behavior are out of this fake's scope
 * (documented gap, M075/S01/T04).
 */

// The fake's own contract: reset in beforeEach AND afterEach so a failing
// test cannot leak its static recordings into the next one.
beforeEach(function () {
    FakePostHogHttpClient::reset();
});

afterEach(function () {
    FakePostHogHttpClient::reset();
});

// A realistic event as the SDK's Consumer serializes it into a /batch/
// payload: the event triple plus the SDK envelope keys capturedEvents()
// must strip.
$batchEvent = function (string $event, string $distinctId): array {
    return [
        'event' => $event,
        'distinct_id' => $distinctId,
        'properties' => ['plan' => 'pro'],
        '$lib' => 'posthog-php',
        '$lib_version' => '3.7.0',
        '$lib_consumer' => 'async',
        '$lib_method' => 'capture',
        'sent_at' => '2026-11-07T00:00:00+00:00',
        'uuid' => 'evt-00000000-0000-0000-0000-000000000001',
        'timestamp' => '2026-11-07T00:00:00+00:00',
    ];
};

describe('FakePostHogHttpClient — request recording', function () use ($batchEvent) {
    it('records path, decoded payload and headers in order', function () use ($batchEvent) {
        $fake = new FakePostHogHttpClient;

        $fake->sendRequest('/batch/', (string) json_encode(['batch' => [$batchEvent('event_a', 'user-1')]]), ['X-Api-Key: phc_test']);
        $fake->sendRequest('/decide?v=3', null);

        expect(FakePostHogHttpClient::$requests)->toBe([
            [
                'path' => '/batch/',
                'payload' => ['batch' => [$batchEvent('event_a', 'user-1')]],
                'headers' => ['X-Api-Key: phc_test'],
            ],
            [
                'path' => '/decide?v=3',
                'payload' => null,
                'headers' => [],
            ],
        ]);
    });

    it('answers every request immediately with 200 ok and no cURL error', function () {
        $response = (new FakePostHogHttpClient)->sendRequest('/batch/', '{"batch": []}');

        expect($response)->toBeInstanceOf(HttpResponse::class)
            ->and($response->getResponseCode())->toBe(200)
            ->and($response->getResponse())->toBe('{"status": "ok"}')
            ->and($response->getCurlErrno())->toBe(0);
    });
});

describe('FakePostHogHttpClient — captured events', function () use ($batchEvent) {
    it('flattens batch payloads across requests in order and strips the SDK envelope keys', function () use ($batchEvent) {
        $fake = new FakePostHogHttpClient;

        $fake->sendRequest('/batch/', (string) json_encode(['batch' => [
            $batchEvent('event_a', 'user-1'),
            $batchEvent('event_b', 'user-2'),
        ]]));
        // A non-batch payload contributes nothing.
        $fake->sendRequest('/decide?v=3', (string) json_encode(['filter' => true]));
        $fake->sendRequest('/batch/', (string) json_encode(['batch' => [
            $batchEvent('event_c', 'user-3'),
        ]]));

        expect(FakePostHogHttpClient::capturedEvents())->toBe([
            ['event' => 'event_a', 'distinct_id' => 'user-1', 'properties' => ['plan' => 'pro']],
            ['event' => 'event_b', 'distinct_id' => 'user-2', 'properties' => ['plan' => 'pro']],
            ['event' => 'event_c', 'distinct_id' => 'user-3', 'properties' => ['plan' => 'pro']],
        ]);
    });

    it('captures nothing when nothing was recorded', function () {
        expect(FakePostHogHttpClient::capturedEvents())->toBe([]);
    });
});

describe('FakePostHogHttpClient — reset', function () {
    it('clears all recorded requests', function () {
        (new FakePostHogHttpClient)->sendRequest('/batch/', '{"batch": []}');

        FakePostHogHttpClient::reset();

        expect(FakePostHogHttpClient::$requests)->toBe([])
            ->and(FakePostHogHttpClient::capturedEvents())->toBe([]);
    });
});

describe('FakePostHogHttpClient — offline construction', function () {
    it('constructs with zero arguments and configures no network target', function () {
        $fake = new FakePostHogHttpClient;

        // The parent constructor requires $host and would configure the
        // cURL dial-out target (host/SSL/timeouts/retry budget). The bypass
        // leaves the parent's private $host untouched — direct evidence no
        // network setup ran.
        $host = (new ReflectionProperty(HttpClient::class, 'host'))->getValue($fake);

        expect($fake)->toBeInstanceOf(HttpClient::class)
            ->and($host)->toBeNull();
    });
});
