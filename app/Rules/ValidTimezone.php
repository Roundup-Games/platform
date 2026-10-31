<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Alias-tolerant IANA timezone validation (R089).
 *
 * Laravel's built-in `timezone` rule is a list lookup against
 * DateTimeZone::listIdentifiers(), which misses tzdata aliases that
 * browsers DO report (Intl CLDR primaries like Asia/Calcutta and
 * America/Buenos_Aires). The DateTimeZone constructor is the authoritative
 * parser: it accepts every alias and rejects Etc/Unknown, offsets, and
 * garbage with an exception.
 *
 * Companion helper: canonical_timezone() returns the canonicalized
 * identifier for storage (DateTimeZone::getName()).
 */
class ValidTimezone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (canonical_timezone($value) === null) {
            $fail('validation.timezone')->translate();
        }
    }
}
