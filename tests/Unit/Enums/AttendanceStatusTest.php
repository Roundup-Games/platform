<?php

use App\Enums\AttendanceStatus;

describe('AttendanceStatus enum', function () {
    it('has exactly 4 cases', function () {
        expect(AttendanceStatus::cases())->toHaveCount(4);
    });

    it('returns correct values', function () {
        expect(AttendanceStatus::values())->toBe([
            'attended',
            'no_show',
            'late_cancel',
            'excused',
        ]);
    });

    it('returns display labels for each case', function () {
        expect(AttendanceStatus::Attended->label())->toBe('Attended');
        expect(AttendanceStatus::NoShow->label())->toBe('No Show');
        expect(AttendanceStatus::LateCancel->label())->toBe('Late Cancel');
        expect(AttendanceStatus::Excused->label())->toBe('Excused');
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
    });

    it('rejects invalid values', function () {
        expect(fn () => AttendanceStatus::from('invalid'))
            ->toThrow(ValueError::class);
    });
});
