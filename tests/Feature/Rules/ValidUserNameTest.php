<?php

use App\Rules\ValidUserName;

/**
 * Extended edge-case tests for ValidUserName rule.
 * Core validation tests are in tests/Feature/ValidUserNameTest.php.
 */
describe('ValidUserName edge cases', function () {
    it('accepts names with digits', function () {
        $rule = new ValidUserName;

        $passed = true;
        $rule->validate('name', 'John Doe 2nd', function () use (&$passed) {
            $passed = false;
        });

        expect($passed)->toBeTrue();
    });

    it('accepts a name with exactly 6 non-space chars and trailing spaces', function () {
        $rule = new ValidUserName;

        $passed = true;
        $rule->validate('name', '  Jonath  ', function () use (&$passed) {
            $passed = false;
        });

        expect($passed)->toBeTrue();
    });

    it('rejects non-string input', function () {
        $rule = new ValidUserName;

        $failed = false;
        $failMessage = null;
        $rule->validate('name', 123456, function ($message) use (&$failed, &$failMessage) {
            $failed = true;
            $failMessage = $message;
        });

        expect($failed)->toBeTrue();
        expect($failMessage)->toContain('string');
    });

    it('rejects an empty string', function () {
        $rule = new ValidUserName;

        $failed = false;
        $rule->validate('name', '', function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeTrue();
    });

    it('rejects name with 5 non-space chars but lots of spaces', function () {
        $rule = new ValidUserName;

        $failed = false;
        $rule->validate('name', 'A   B   C   D   E', function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeTrue();
    });

    it('accepts name with tab and newline as whitespace', function () {
        $rule = new ValidUserName;

        // Tabs and newlines are \s (whitespace), which is preserved by sanitize
        $passed = true;
        $rule->validate('name', "John\tDoe\nSmith", function () use (&$passed) {
            $passed = false;
        });

        expect($passed)->toBeTrue();
    });

    it('accepts names with only hyphens and letters', function () {
        $rule = new ValidUserName;

        $passed = true;
        $rule->validate('name', 'Jean-Pierre', function () use (&$passed) {
            $passed = false;
        });

        expect($passed)->toBeTrue();
    });

    it('stops at first error when name has emojis (does not check further)', function () {
        $rule = new ValidUserName;

        $failMessage = null;
        $rule->validate('name', '🎮🎮🎮🎮🎮🎮', function ($message) use (&$failMessage) {
            $failMessage = $message;
        });

        // Should get emoji error, not the special chars or length error
        expect($failMessage)->toContain('emojis');
    });
});

describe('ValidUserName::sanitize edge cases', function () {
    it('handles empty string', function () {
        expect(ValidUserName::sanitize(''))->toBe('');
    });

    it('strips parentheses and brackets', function () {
        expect(ValidUserName::sanitize('John (Johnny) [Doe]'))->toBe('John Johnny Doe');
    });

    it('strips dollar signs and percent signs preserving space', function () {
        expect(ValidUserName::sanitize('$100% Off'))->toBe('100 Off');
    });

    it('preserves digits', function () {
        expect(ValidUserName::sanitize('User123'))->toBe('User123');
    });

    it('handles string with only emojis producing empty result', function () {
        expect(ValidUserName::sanitize('🎮🎲🎯'))->toBe('');
    });
});

describe('ValidUserName::containsEmojis edge cases', function () {
    it('detects flag emojis', function () {
        expect(ValidUserName::containsEmojis('🇺🇸 USA'))->toBeTrue();
    });

    it('detects skin tone modifier emojis', function () {
        expect(ValidUserName::containsEmojis('👍🏽'))->toBeTrue();
    });

    it('does not false-positive on currency symbols', function () {
        expect(ValidUserName::containsEmojis('$100 €50'))->toBeFalse();
    });

    it('does not false-positive on math symbols', function () {
        expect(ValidUserName::containsEmojis('2+2=4'))->toBeFalse();
    });
});
