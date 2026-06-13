<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\App;

beforeEach(function () {
    // Reset to a known state before each test
    App::setLocale('en');
});

describe('format_date — locale-aware', function () {
    it('format_date returns locale-specific date format', function (string $locale, string $type, string $dateInput, string $expected) {
        if ($locale !== 'en') {
            App::setLocale($locale);
        }
        $date = Carbon::createFromFormat('Y-m-d H:i:s', $dateInput);
        expect(format_date($date, $type))->toBe($expected);
    })->with([
        // English locale
        ['en', 'date',          '2026-04-14 00:00:00', 'Apr 14, 2026'],
        ['en', 'short_date',    '2026-04-14 00:00:00', 'Apr 14'],
        ['en', 'datetime',      '2026-04-14 14:30:00', 'Apr 14, 2026 at 2:30 PM'],
        ['en', 'short_month_day', '2026-04-14 00:00:00', 'Apr 14, 2026'],
        // German locale
        ['de', 'date',          '2026-04-14 00:00:00', '14. April 2026'],
        ['de', 'short_date',    '2026-04-14 00:00:00', '14. Apr'],
        ['de', 'datetime',      '2026-04-14 14:30:00', '14. April 2026, 14:30'],
        ['de', 'short_month_day', '2026-04-14 00:00:00', '14. April 2026'],
    ]);

    it('format_date defaults to date type', function (string $locale, string $expected) {
        if ($locale !== 'en') {
            App::setLocale($locale);
        }
        $date = Carbon::create(2026, 4, 14, 0, 0, 0);
        expect(format_date($date))->toBe($expected);
    })->with([
        ['en', 'Apr 14, 2026'],
        ['de', '14. April 2026'],
    ]);
});

describe('format_date — Edge cases', function () {
    test('format_date returns empty string for null date', function () {
        expect(format_date(null))->toBe('');
    });

    test('format_date handles single-digit day', function () {
        $date = Carbon::create(2026, 1, 5, 0, 0, 0);
        expect(format_date($date, 'date'))->toBe('Jan 5, 2026');
    });

    test('format_date handles midnight time in datetime', function () {
        $date = Carbon::create(2026, 12, 25, 0, 0, 0);
        expect(format_date($date, 'datetime'))->toBe('Dec 25, 2026 at 12:00 AM');
    });
});

describe('format_currency — locale-aware', function () {
    it('format_currency formats amount correctly per locale', function (string $locale, int $amount, bool $inCents, string $expected) {
        if ($locale !== 'en') {
            App::setLocale($locale);
        }
        expect(format_currency($amount, $inCents))->toBe($expected);
    })->with([
        // English locale
        ['en', 500,    true,  '$5.00'],
        ['en', 5,      false, '$5.00'],
        ['en', 0,      true,  'Free'],
        ['en', 0,      false, 'Free'],
        ['en', 99,     true,  '$0.99'],
        ['en', 123456, true,  '$1,234.56'],
        // German locale
        ['de', 500,    true,  '5,00 €'],
        ['de', 5,      false, '5,00 €'],
        ['de', 0,      true,  'Kostenlos'],
        ['de', 0,      false, 'Kostenlos'],
        ['de', 99,     true,  '0,99 €'],
        ['de', 123456, true,  '1.234,56 €'],
    ]);
});

describe('format_currency — Edge cases', function () {
    test('format_currency handles amount of 1 cent', function () {
        expect(format_currency(1, true))->toBe('$0.01');
    });

    test('format_currency handles whole dollar amount', function () {
        expect(format_currency(1000, true))->toBe('$10.00');
    });
});

describe('to_string_id — identifier coercion', function () {
    test('it coerces a UUID string unchanged', function () {
        expect(to_string_id('550e8400-e29b-41d4-a716-446655440000'))
            ->toBe('550e8400-e29b-41d4-a716-446655440000');
    });

    test('it coerces an integer to its string form', function () {
        expect(to_string_id(42))->toBe('42');
        expect(to_string_id(0))->toBe('0');
    });

    test('it coerces a numeric string unchanged', function () {
        expect(to_string_id('99'))->toBe('99');
    });

    test('it returns empty string for non-identifier values', function () {
        foreach ([null, true, false, 3.14, [1, 2], new stdClass] as $input) {
            expect(to_string_id($input))->toBe('');
        }
    });
});
