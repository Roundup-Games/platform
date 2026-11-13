<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * Replace invalid UTF-8 byte sequences in request input with the
 * mbstring substitute character.
 *
 * Laravel's `string` and `max` validation rules accept invalid UTF-8,
 * and Postgres rejects it on insert (SQLSTATE 22021), so a client
 * posting a lone UTF-16 surrogate to a public text form — seen once on
 * the guest contact form (error tracking 2026-11-11) — got a 500.
 * Browsers never emit such bytes; scrubbing at the request boundary
 * protects every form with one change and keeps the submission usable:
 * a human on a broken client keeps their message, with `?` in place of
 * the damaged bytes.
 *
 * Only parsed input changes: query strings, form bodies and JSON bags.
 * The raw request body ($request->getContent()) is untouched, so
 * webhook signature verification (Paddle, Resend, Discord) still reads
 * the bytes exactly as received.
 */
class ScrubInvalidUtf8 extends TransformsRequest
{
    protected function transform($key, $value)
    {
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            return mb_scrub($value, 'UTF-8');
        }

        return $value;
    }
}
