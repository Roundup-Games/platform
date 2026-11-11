<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * Replace invalid UTF-8 byte sequences in request input strings.
 *
 * Laravel's `string` and `max` validation rules accept invalid UTF-8, and
 * Postgres rejects it on insert (SQLSTATE 22021), so a lone UTF-16 surrogate
 * posted to any public text form became a 500. Browsers never send such
 * bytes, but scripts do. Scrubbing here protects every form at once. Each
 * invalid sequence becomes the mbstring substitute character.
 *
 * Only parsed input (query, form and JSON bodies) changes — the raw body
 * that the webhook signature verifiers read stays as received.
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
