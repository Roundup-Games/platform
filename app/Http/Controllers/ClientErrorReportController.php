<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Consent-independent runtime-error beacon endpoint.
 *
 * The client beacon (resources/js/client-errors.js) POSTs a minimal,
 * PII-free error fingerprint here — truncated message, source location, a
 * one-way stack digest (never the stack itself) and the page path; no user
 * identifiers, no storage reads. It exists because posthog-js only captures
 * exceptions for consented sessions: without this bridge, runtime errors
 * from non-consenting users never reach the server-side alerting pipeline.
 *
 * Failure posture mirrors TimezoneBeaconController: invalid payloads answer
 * 422, everything else answers 204. The log write is wrapped defensively —
 * a degraded log channel must never surface a second error to an
 * already-crashing client. Throttle + CSRF live on the route; guests are
 * allowed by design. No DB writes.
 */
class ClientErrorReportController extends Controller
{
    /**
     * Record one client-side runtime error report in the error log.
     */
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:200'],
            'file' => ['nullable', 'string', 'max:500'],
            'line' => ['nullable', 'integer', 'min:0'],
            'col' => ['nullable', 'integer', 'min:0'],
            'digest' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            Log::error('js.runtime_error', $validated + [
                'ip_hash' => hash('sha256', (string) $request->ip()),
            ]);
        } catch (Throwable) {
            // Degraded log channel — drop the report rather than erroring
            // out toward an already-crashing client.
        }

        return response()->noContent();
    }
}
