<?php

namespace App\Console\Concerns;

/**
 * Parse Artisan options that must be a positive integer.
 *
 * Numeric CLI options that feed destructive queries (DELETE, mass flag writes)
 * must never be allowed to coerce to 0: a 0 max-age makes the cutoff "now" and
 * matches every row, silently wiping the table. This trait gives commands one
 * helper that validates up front and aborts the command on an invalid value.
 *
 * Contract of {@see positiveIntegerOption()}:
 *   - option absent (null)  → $resolved = null, returns true  (caller treats as "no constraint")
 *   - valid positive int    → $resolved = (int) value, returns true
 *   - invalid (0, negative, non-numeric, empty, fraction) → emits error, returns false
 *
 * ctype_digit rejects '', '-1', and '2.5' alike (for string input from the
 * CLI), so callers need no extra guards. Integer input (from test arrays like
 * `['--hours' => 10]`) is validated directly. Empty string ('--opt=') is
 * treated as an explicit malformed value and rejected rather than coerced
 * to 0 — the valueless-input case that previously caused data loss in the
 * prune commands.
 *
 * Example:
 *   if (! $this->positiveIntegerOption('max-age', $maxAge, 'days')) {
 *       return self::FAILURE;
 *   }
 */
trait ParsesPositiveIntegerOptions
{
    /**
     * Resolve a console option to a positive integer, aborting the command on
     * an invalid value.
     *
     * @param  string  $name  Option name (without the leading --).
     * @param  int|null  $resolved  Out: the parsed value, or null when the option is absent.
     * @param  string  $unit  Optional unit appended to the error message (e.g. 'days').
     * @return bool True if the option is valid or absent; false if present-but-invalid
     *              (the caller should `return self::FAILURE`).
     */
    protected function positiveIntegerOption(string $name, ?int &$resolved, string $unit = ''): bool
    {
        // Always initialise the out-param so it is defined on every return path.
        $resolved = null;

        $raw = $this->option($name);
        if ($raw === null) {
            return true; // absent → no constraint
        }

        // $this->option() returns array|bool|float|int|string. Handle the two
        // legitimate types (string from the CLI, int from test arrays) explicitly;
        // reject everything else (array, bool, float) up front.
        if (is_int($raw)) {
            if ($raw <= 0) {
                $this->error(sprintf(
                    'The --%s option must be a positive integer%s.',
                    $name,
                    $unit !== '' ? " ({$unit})" : '',
                ));

                return false;
            }
            $resolved = $raw;

            return true;
        }

        if (! is_string($raw) || ! ctype_digit($raw) || (int) $raw <= 0) {
            $this->error(sprintf(
                'The --%s option must be a positive integer%s.',
                $name,
                $unit !== '' ? " ({$unit})" : '',
            ));

            return false;
        }

        $resolved = (int) $raw;

        return true;
    }
}
