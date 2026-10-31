<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TimezoneCaptureService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Authenticated device-timezone beacon endpoint (R089).
 *
 * The on-load JS beacon (resources/js/timezone-beacon.js) POSTs here ONLY
 * when the detected IANA zone differs from the cookie-guarded last-sent
 * value — never once per request. TimezoneCaptureService enforces the same
 * contract as the login/OAuth captures: alias-tolerant canonicalization,
 * no write when unchanged, and no write over a manually-set profile zone.
 *
 * Silent degradation is total: the endpoint always answers 204 — invalid or
 * absent payloads are dropped without error so a misbehaving client never
 * sees failures it cannot act on. Auth + throttle live on the route.
 */
class TimezoneBeaconController extends Controller
{
    public function __construct(
        private readonly TimezoneCaptureService $capture,
    ) {}

    /**
     * Store the beaconed device timezone for the authenticated user.
     */
    public function store(Request $request): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->capture->capture($user, $request->input('timezone'));
        }

        return response()->noContent();
    }
}
