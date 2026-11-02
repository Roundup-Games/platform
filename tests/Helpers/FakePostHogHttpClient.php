<?php

namespace Tests\Helpers;

use PostHog\HttpClient;
use PostHog\HttpResponse;
use Tests\Feature\PostHog\PostHogEventCaptureTest;

/**
 * Offline transport for the PostHog SDK in tests.
 *
 * The SDK's default LibCurl consumer performs REAL network I/O. With a fake
 * API key that means connection attempts, retry backoff and cURL timeouts —
 * the 2026-11-02 harness runtime report traced a 1-20s (network-state
 * dependent) test to exactly this. Injecting this transport keeps every
 * PostHog test deterministic and instant, and makes the actually-captured
 * payloads assertable instead of trusting the artisan output alone:
 *
 *     PostHog::init(
 *         'phc_test_key',
 *         ['host' => 'https://eu.i.posthog.com'],
 *         new Client('phc_test_key', ['host' => 'https://eu.i.posthog.com', 'batch_size' => 1], new FakePostHogHttpClient),
 *     );
 *
 * @see PostHogEventCaptureTest
 */
class FakePostHogHttpClient extends HttpClient
{
    /**
     * Bypass the parent constructor (its $host is required and only feeds
     * real cURL setup the fake never performs).
     */
    public function __construct()
    {
        // intentionally no parent::__construct()
    }

    /**
     * Every request the SDK attempted, in order:
     * ['path' => string, 'payload' => array|null (decoded), 'headers' => array].
     *
     * @var array<int, array{path: string, payload: array|null, headers: array}>
     */
    public static array $requests = [];

    public function sendRequest(string $path, ?string $payload, array $extraHeaders = [], array $requestOptions = []): HttpResponse
    {
        self::$requests[] = [
            'path' => $path,
            'payload' => $payload === null ? null : json_decode($payload, true),
            'headers' => $extraHeaders,
        ];

        return new HttpResponse('{"status": "ok"}', 200);
    }

    /**
     * Clear the recorded requests. Call in beforeEach AND afterEach so a
     * failing test cannot leak its recordings into the next one.
     */
    public static function reset(): void
    {
        self::$requests = [];
    }

    /**
     * All events captured across recorded /batch/ requests, flattened.
     * SDK envelope keys ($lib, $lib_version, sent_at…) are stripped so
     * assertions target the event under test.
     *
     * @return array<int, array{event: mixed, distinct_id: mixed, properties: array}>
     */
    public static function capturedEvents(): array
    {
        $events = [];
        foreach (self::$requests as $request) {
            foreach ($request['payload']['batch'] ?? [] as $event) {
                foreach (['$lib', '$lib_version', '$lib_consumer', '$lib_method', 'sent_at', 'uuid', 'timestamp'] as $envelope) {
                    unset($event[$envelope]);
                }
                $events[] = $event;
            }
        }

        return $events;
    }
}
