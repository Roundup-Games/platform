<?php

use App\Enums\AttendanceStatus;

describe('AttendanceStatus enum', function () {
    it('has exactly 5 cases', function () {
        expect(AttendanceStatus::cases())->toHaveCount(5);
    });

    it('returns correct values', function () {
        expect(AttendanceStatus::values())->toBe([
            'attended',
            'no_show',
            'late_cancel',
            'excused',
            'cancelled_early',
        ]);
    });

    it('returns display labels for each case', function () {
        expect(AttendanceStatus::Attended->label())->toBe('Attended');
        expect(AttendanceStatus::NoShow->label())->toBe('No Show');
        expect(AttendanceStatus::LateCancel->label())->toBe('Late Cancel');
        expect(AttendanceStatus::Excused->label())->toBe('Excused');
        expect(AttendanceStatus::CancelledEarly->label())->toBe('Cancelled Early');
    });

    it('includes cancelled_early in values', function () {
        expect(AttendanceStatus::values())->toContain('cancelled_early');
    });

    it('has label for cancelled_early', function () {
        expect(AttendanceStatus::CancelledEarly->label())->not->toBeEmpty()
            ->and(AttendanceStatus::CancelledEarly->label())->toBe('Cancelled Early');
    });

    it('is backed by string type', function () {
        $reflection = new ReflectionEnum(AttendanceStatus::class);
        expect($reflection->getBackingType()?->getName())->toBe('string');
    });

    it('can be instantiated from a string value', function () {
        expect(AttendanceStatus::from('attended'))->toBe(AttendanceStatus::Attended);
        expect(AttendanceStatus::from('no_show'))->toBe(AttendanceStatus::NoShow);
        expect(AttendanceStatus::from('late_cancel'))->toBe(AttendanceStatus::LateCancel);
        expect(AttendanceStatus::from('excused'))->toBe(AttendanceStatus::Excused);
        expect(AttendanceStatus::from('cancelled_early'))->toBe(AttendanceStatus::CancelledEarly);
    });

    it('rejects invalid values', function () {
        expect(fn () => AttendanceStatus::from('invalid'))
            ->toThrow(ValueError::class);
    });
});
