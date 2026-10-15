<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the IndexNow ownership key file at /{key}.txt — engines fetch it
 * to verify that the pinging host really controls the domain before they
 * accept submissions. The key is an ownership proof, not a secret
 * (it travels in every ping payload by protocol design).
 *
 * 404 unless the requested filename matches the configured key exactly —
 * random probing must not reveal whether IndexNow is enabled.
 */
class IndexNowKeyController extends Controller
{
    public function __invoke(Request $request, string $keyfile): Response
    {
        $key = config('services.indexnow.key');

        if (! is_string($key) || $keyfile !== "{$key}.txt") {
            abort(404);
        }

        return response($key, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
