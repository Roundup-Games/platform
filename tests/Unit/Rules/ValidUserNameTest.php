<?php

use App\Rules\ValidUserName;

describe('ValidUserName rule', function () {
    it('accepts a valid name with 6+ non-space chars', function () {
        $rule = new ValidUserName;

        $passed = true;
        $rule->validate('name', 'John Doe Smith', function () use (&$passed) {
            $passed = false;
        });

        expect($passed)->toBeTrue();
    });

    it('accepts a name with exactly 6 non-space chars', function () {
        $rule = new ValidUserName;

        $passed = true;
        $rule->validate('name', 'Jonath', function () use (&$passed) {
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

    it('accepts names with accents and unicode letters', function () {
        $rule = new ValidUserName;

        $passed = true;
        $rule->validate('name', 'François Müller', function () use (&$passed) {
            $passed = false;
        });

        expect($passed)->toBeTrue();
    });

    it('accepts names with hyphens', function () {
        $rule = new ValidUserName;

        $passed = true;
        $rule->validate('name', 'Mary-Jane Watson', function () use (&$passed) {
            $passed = false;
        });

        expect($passed)->toBeTrue();
    });

    it('accepts names with underscores', function () {
        $rule = new ValidUserName;

        $passed = true;
        $rule->validate('name', 'John_Doe Test', function () use (&$passed) {
            $passed = false;
        });

        expect($passed)->toBeTrue();
    });

    it('accepts names with digits', function () {
        $rule = new ValidUserName;

        $passed = true;
        $rule->validate('name', 'John Doe 2nd', function () use (&$passed) {
            $passed = false;
        });

        expect($passed)->toBeTrue();
    });

    it('accepts name with tab and newline as whitespace', function () {
        $rule = new ValidUserName;

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

    it('rejects a name with fewer than 6 non-space chars', function () {
        $rule = new ValidUserName;

        $failed = false;
        $failMessage = null;
        $rule->validate('name', 'Jo D', function ($message) use (&$failed, &$failMessage) {
            $failed = true;
            $failMessage = $message;
        });

        expect($failed)->toBeTrue();
        expect($failMessage)->toContain('6 non-space characters');
    });

    it('rejects a name with 5 non-space chars but lots of spaces', function () {
        $rule = new ValidUserName;

        $failed = false;
        $rule->validate('name', 'A   B   C   D   E', function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeTrue();
    });

    it('rejects a name with only spaces', function () {
        $rule = new ValidUserName;

        $failed = false;
        $rule->validate('name', '      ', function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeTrue();
    });

    it('rejects an empty string', function () {
        $rule = new ValidUserName;

        $failed = false;
        $rule->validate('name', '', function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeTrue();
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

    it('rejects a name containing emojis', function () {
        $rule = new ValidUserName;

        $failed = false;
        $failMessage = null;
        $rule->validate('name', 'John Doe 🎮', function ($message) use (&$failed, &$failMessage) {
            $failed = true;
            $failMessage = $message;
        });

        expect($failed)->toBeTrue();
        expect($failMessage)->toContain('emojis');
    });

    it('stops at first error when name has emojis', function () {
        $rule = new ValidUserName;

        $failMessage = null;
        $rule->validate('name', '🎮🎮🎮🎮🎮🎮', function ($message) use (&$failMessage) {
            $failMessage = $message;
        });

        // Should get emoji error, not the special chars or length error
        expect($failMessage)->toContain('emojis');
    });

    it('rejects a name containing special characters', function () {
        $rule = new ValidUserName;

        $failed = false;
        $failMessage = null;
        $rule->validate('name', 'John@Doe#Test', function ($message) use (&$failed, &$failMessage) {
            $failed = true;
            $failMessage = $message;
        });

        expect($failed)->toBeTrue();
        expect($failMessage)->toContain('special characters');
    });

    it('rejects a name with HTML tags', function () {
        $rule = new ValidUserName;

        $failed = false;
        $rule->validate('name', '<script>alert(1)</script>', function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeTrue();
    });
});

describe('ValidUserName::sanitize', function () {
    it('strips emojis from name', function () {
        expect(ValidUserName::sanitize('John 🎮 Doe'))->toBe('John  Doe');
    });

    it('strips special characters from name', function () {
        expect(ValidUserName::sanitize('John@Doe#Test!'))->toBe('JohnDoeTest');
    });

    it('preserves letters, spaces, hyphens, underscores', function () {
        expect(ValidUserName::sanitize('Mary-Jane_Test User'))->toBe('Mary-Jane_Test User');
    });

    it('preserves unicode letters', function () {
        expect(ValidUserName::sanitize('François Müller'))->toBe('François Müller');
    });

    it('preserves casing', function () {
        expect(ValidUserName::sanitize('John Doe'))->toBe('John Doe');
    });

    it('returns clean name unchanged', function () {
        expect(ValidUserName::sanitize('John Doe Smith'))->toBe('John Doe Smith');
    });

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

describe('ValidUserName::containsEmojis', function () {
    it('detects common emojis', function () {
        expect(ValidUserName::containsEmojis('Hello 🎮'))->toBeTrue();
        expect(ValidUserName::containsEmojis('😀 grin'))->toBeTrue();
        expect(ValidUserName::containsEmojis('❤️ love'))->toBeTrue();
    });

    it('returns false for clean names', function () {
        expect(ValidUserName::containsEmojis('John Doe'))->toBeFalse();
        expect(ValidUserName::containsEmojis('François'))->toBeFalse();
    });

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
