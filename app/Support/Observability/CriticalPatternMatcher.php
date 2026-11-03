<?php

namespace App\Support\Observability;

/**
 * Single home for the "does this log message page the operator" policy
 * (observability 2026-11-03): case-insensitive matching against
 * config('observability.critical_patterns').
 *
 * Patterns are treated as regex so entries like
 * "paddle webhook.*manual reconciliation" can span the variable middle of
 * a real message ("Paddle webhook: event registration paid by a different
 * transaction (needs manual reconciliation)"). A dot in a plain prefix
 * ("badges.sweep.") therefore matches any character in its position —
 * deliberately broader, harmless for this curated list. A pattern that is
 * not valid regex falls back to a literal case-insensitive substring match
 * so a config typo degrades to literal matching instead of crashing the
 * scan.
 */
class CriticalPatternMatcher
{
    /**
     * Does the message match any configured critical pattern?
     *
     * @param  string  $message  The log/alertable-error message text.
     * @param  list<string>|null  $patterns  Overrides the config for testing.
     */
    public static function matches(string $message, ?array $patterns = null): bool
    {
        foreach ($patterns ?? self::patterns() as $pattern) {
            if ($pattern === '') {
                continue;
            }

            // Null-byte delimiter: none of the curated patterns contain it,
            // and it cannot collide with regex syntax inside the pattern.
            $result = @preg_match("\x01{$pattern}\x01i", $message);

            if ($result === false) {
                // Invalid regex — degrade to a literal substring check.
                if (stripos($message, $pattern) !== false) {
                    return true;
                }

                continue;
            }

            if ($result === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The configured pattern list, sanitized to non-empty strings.
     *
     * @return list<string>
     */
    public static function patterns(): array
    {
        $configured = config('observability.critical_patterns', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            $configured,
            fn (mixed $pattern): bool => is_string($pattern) && $pattern !== '',
        ));
    }
}
