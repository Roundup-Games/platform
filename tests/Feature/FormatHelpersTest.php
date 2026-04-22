<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\App;

beforeEach(function () {
    // Reset to a known state before each test
    App::setLocale('en');
});

describe('format_date — English locale', function () {
    test('format_date returns English date format', function () {
        $date = Carbon::create(2026, 4, 14, 0, 0, 0);
        expect(format_date($date, 'date'))->toBe('Apr 14, 2026');
    });

    test('format_date returns English short_date format', function () {
        $date = Carbon::create(2026, 4, 14, 0, 0, 0);
        expect(format_date($date, 'short_date'))->toBe('Apr 14');
    });

    test('format_date returns English datetime format', function () {
        $date = Carbon::create(2026, 4, 14, 14, 30, 0);
        expect(format_date($date, 'datetime'))->toBe('Apr 14, 2026 at 2:30 PM');
    });

    test('format_date returns English short_month_day format', function () {
        $date = Carbon::create(2026, 4, 14, 0, 0, 0);
        expect(format_date($date, 'short_month_day'))->toBe('Apr 14, 2026');
    });

    test('format_date defaults to date type', function () {
        $date = Carbon::create(2026, 4, 14, 0, 0, 0);
        expect(format_date($date))->toBe('Apr 14, 2026');
    });
});

describe('format_date — German locale', function () {
    test('format_date returns German date format', function () {
        App::setLocale('de');
        $date = Carbon::create(2026, 4, 14, 0, 0, 0);
        expect(format_date($date, 'date'))->toBe('14. April 2026');
    });

    test('format_date returns German short_date format', function () {
        App::setLocale('de');
        $date = Carbon::create(2026, 4, 14, 0, 0, 0);
        expect(format_date($date, 'short_date'))->toBe('14. Apr');
    });

    test('format_date returns German datetime format', function () {
        App::setLocale('de');
        $date = Carbon::create(2026, 4, 14, 14, 30, 0);
        expect(format_date($date, 'datetime'))->toBe('14. April 2026, 14:30');
    });

    test('format_date returns German short_month_day same as date', function () {
        App::setLocale('de');
        $date = Carbon::create(2026, 4, 14, 0, 0, 0);
        expect(format_date($date, 'short_month_day'))->toBe('14. April 2026');
    });

    test('format_date defaults to date type in German', function () {
        App::setLocale('de');
        $date = Carbon::create(2026, 4, 14, 0, 0, 0);
        expect(format_date($date))->toBe('14. April 2026');
    });
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

describe('format_currency — English locale', function () {
    test('format_currency formats cents in English', function () {
        expect(format_currency(500, true))->toBe('$5.00');
    });

    test('format_currency formats dollars when inCents is false', function () {
        expect(format_currency(5, false))->toBe('$5.00');
    });

    test('format_currency shows Free for zero cents in English', function () {
        expect(format_currency(0, true))->toBe('Free');
    });

    test('format_currency shows Free for zero dollars in English', function () {
        expect(format_currency(0, false))->toBe('Free');
    });

    test('format_currency handles single-digit cent amounts', function () {
        expect(format_currency(99, true))->toBe('$0.99');
    });

    test('format_currency handles large amounts', function () {
        expect(format_currency(123456, true))->toBe('$1,234.56');
    });
});

describe('format_currency — German locale', function () {
    test('format_currency formats cents in German', function () {
        App::setLocale('de');
        expect(format_currency(500, true))->toBe('5,00 €');
    });

    test('format_currency formats dollars when inCents is false in German', function () {
        App::setLocale('de');
        expect(format_currency(5, false))->toBe('5,00 €');
    });

    test('format_currency shows Kostenlos for zero in German', function () {
        App::setLocale('de');
        expect(format_currency(0, true))->toBe('Kostenlos');
    });

    test('format_currency shows Kostenlos for zero dollars in German', function () {
        App::setLocale('de');
        expect(format_currency(0, false))->toBe('Kostenlos');
    });

    test('format_currency handles single-digit cent amounts in German', function () {
        App::setLocale('de');
        expect(format_currency(99, true))->toBe('0,99 €');
    });

    test('format_currency handles large amounts in German', function () {
        App::setLocale('de');
        expect(format_currency(123456, true))->toBe('1.234,56 €');
    });
});

describe('format_currency — Edge cases', function () {
    test('format_currency handles amount of 1 cent', function () {
        expect(format_currency(1, true))->toBe('$0.01');
    });

    test('format_currency handles whole dollar amount', function () {
        expect(format_currency(1000, true))->toBe('$10.00');
    });
});
