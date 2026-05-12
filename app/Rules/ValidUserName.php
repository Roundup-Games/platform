<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidUserName implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * Names must:
     * - Contain at least 6 non-space characters
     * - Not contain emojis
     * - Not contain special characters (only word chars, spaces, dashes, underscores)
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        // Check for emojis (Unicode emoji ranges)
        if ($this->containsEmojis($value)) {
            $fail('The :attribute cannot contain emojis.');

            return;
        }

        // Check for special characters — keep only \w (word chars), spaces, dashes, underscores
        // \w in Unicode mode matches \p{L}\p{N}_ so we allow letters, digits, underscore, plus spaces and hyphens
        $stripped = $this->sanitize($value);

        if ($stripped !== $value) {
            $fail('The :attribute contains invalid special characters.');
        }

        // Count non-space characters
        $nonSpaceCount = mb_strlen(preg_replace('/\s/u', '', $stripped));

        if ($nonSpaceCount < 6) {
            $fail('The :attribute must contain at least 6 non-space characters.');
        }
    }

    /**
     * Sanitize a name by stripping emojis and special characters.
     * Keeps letters, digits, underscores, spaces, and hyphens.
     * Preserves original casing and structure.
     */
    public static function sanitize(string $name): string
    {
        // First strip emojis
        $name = self::stripEmojis($name);

        // Strip special characters — keep only \w (word chars), spaces, hyphens
        // \w in Unicode mode = \p{L}\p{N}_ (letters, numbers, underscore)
        $name = preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $name);

        return $name;
    }

    /**
     * Check whether a string contains emoji characters.
     */
    public static function containsEmojis(string $value): bool
    {
        return preg_match(self::emojiRegex(), $value) === 1;
    }

    /**
     * Strip emoji characters from a string.
     */
    public static function stripEmojis(string $value): string
    {
        return preg_replace(self::emojiRegex(), '', $value);
    }

    /**
     * Unicode emoji regex pattern covering common emoji ranges.
     */
    private static function emojiRegex(): string
    {
        // Covers: Miscellaneous Symbols and Pictographs, Emoticons, Transport & Map,
        // Supplemental Symbols and Pictographs, flags (regional indicators),
        // variation selectors, zero-width joiners, and combining marks used in emoji sequences
        return '/'
            . '\x{FE00}-\x{FE0F}'       // Variation Selectors (VS1-VS16)
            . '|\x{200D}'                // Zero Width Joiner
            . '|\x{20E3}'                // Combining Enclosing Keycap
            . '|[\x{1F600}-\x{1F64F}]'   // Emoticons
            . '|[\x{1F300}-\x{1F5FF}]'   // Misc Symbols and Pictographs
            . '|[\x{1F680}-\x{1F6FF}]'   // Transport and Map
            . '|[\x{1F1E0}-\x{1F1FF}]'   // Flags (Regional Indicators)
            . '|[\x{2600}-\x{26FF}]'     // Misc Symbols
            . '|[\x{2700}-\x{27BF}]'     // Dingbats
            . '|[\x{FE00}-\x{FE0F}]'     // Variation Selectors
            . '|[\x{1F900}-\x{1F9FF}]'   // Supplemental Symbols and Pictographs
            . '|[\x{1FA00}-\x{1FA6F}]'   // Chess Symbols
            . '|[\x{1FA70}-\x{1FAFF}]'   // Symbols and Pictographs Extended-A
            . '|[\x{231A}-\x{231B}]'     // Watch, Hourglass
            . '|[\x{23E9}-\x{23F3}]'     // Media control symbols
            . '|[\x{23F8}-\x{23FA}]'     // Media control symbols
            . '|[\x{25AA}-\x{25AB}]'     // Small squares
            . '|[\x{25B6}]'              // Play button
            . '|[\x{25C0}]'              // Reverse button
            . '|[\x{25FB}-\x{25FE}]'     // Medium squares
            . '|[\x{2614}-\x{2615}]'     // Umbrella, hot beverage
            . '|[\x{2648}-\x{2653}]'     // Zodiac
            . '|[\x{267F}]'              // wheelchair
            . '|[\x{2693}]'              // anchor
            . '|[\x{26A1}]'              // high voltage
            . '|[\x{26AA}-\x{26AB}]'     // circles
            . '|[\x{26BD}-\x{26BE}]'     // sports
            . '|[\x{26C4}-\x{26C5}]'     // snowman, sun
            . '|[\x{26CE}]'              // ophiuchus
            . '|[\x{26D4}]'              // no entry
            . '|[\x{26EA}]'              // church
            . '|[\x{26F2}-\x{26F3}]'     // fountain, golf
            . '|[\x{26F5}]'              // sailboat
            . '|[\x{26FA}]'              // tent
            . '|[\x{26FD}]'              // fuel pump
            . '|[\x{2702}]'              // scissors
            . '|[\x{2705}]'              // check mark button
            . '|[\x{2708}-\x{270D}]'     // airplane, writing
            . '|[\x{270F}]'              // pencil
            . '|[\x{2712}]'              // black nib
            . '|[\x{2714}]'              // check mark
            . '|[\x{2716}]'              // multiplication
            . '|[\x{271D}]'              // latin cross
            . '|[\x{2721}]'              // star of david
            . '|[\x{2728}]'              // sparkles
            . '|[\x{2733}-\x{2734}]'     // eight-pointed star
            . '|[\x{2744}]'              // snowflake
            . '|[\x{2747}]'              // sparkle
            . '|[\x{274C}]'              // cross mark
            . '|[\x{274E}]'              // cross mark
            . '|[\x{2753}-\x{2755}]'     // question marks
            . '|[\x{2757}]'              // exclamation
            . '|[\x{2763}-\x{2764}]'     // heart exclamation, red heart
            . '|[\x{2795}-\x{2797}]'     // math symbols
            . '|[\x{27A1}]'              // right arrow
            . '|[\x{27B0}]'              // curly loop
            . '|[\x{27BF}]'              // double curly loop
            . '|[\x{2934}-\x{2935}]'     // arrows
            . '|[\x{2B05}-\x{2B07}]'     // arrows
            . '|[\x{2B1B}-\x{2B1C}]'     // black/white squares
            . '|[\x{2B50}]'              // star
            . '|[\x{2B55}]'              // circle
            . '|[\x{3030}]'              // wavy dash
            . '|[\x{303D}]'              // part alternation mark
            . '|[\x{3297}]'              // circled ideograph congratulation
            . '|[\x{3299}]'              // circled ideograph secret
            . '/u';
    }
}
