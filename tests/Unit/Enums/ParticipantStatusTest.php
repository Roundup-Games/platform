<?php

use App\Enums\ParticipantStatus;

describe('ParticipantStatus enum', function () {
    it('has exactly 5 cases', function () {
        expect(ParticipantStatus::cases())->toHaveCount(5);
    });

    it('returns correct values', function () {
        expect(ParticipantStatus::values())->toBe([
            'approved',
            'rejected',
            'pending',
            'waitlisted',
            'benched',
        ]);
    });

    it('includes waitlisted and benched cases', function () {
        expect(ParticipantStatus::Waitlisted->value)->toBe('waitlisted');
        expect(ParticipantStatus::Benched->value)->toBe('benched');
    });

    it('returns display labels for each case', function () {
        expect(ParticipantStatus::Approved->label())->toBe('Approved');
        expect(ParticipantStatus::Rejected->label())->toBe('Rejected');
        expect(ParticipantStatus::Pending->label())->toBe('Pending');
        expect(ParticipantStatus::Waitlisted->label())->toBe('Waitlisted');
        expect(ParticipantStatus::Benched->label())->toBe('Benched');
    });

    it('is backed by string type', function () {
        $reflection = new ReflectionEnum(ParticipantStatus::class);
        expect($reflection->getBackingType()?->getName())->toBe('string');
    });

    it('can be instantiated from a string value', function () {
        expect(ParticipantStatus::from('approved'))->toBe(ParticipantStatus::Approved);
        expect(ParticipantStatus::from('waitlisted'))->toBe(ParticipantStatus::Waitlisted);
        expect(ParticipantStatus::from('benched'))->toBe(ParticipantStatus::Benched);
    });

    it('rejects invalid values', function () {
        expect(fn () => ParticipantStatus::from('invalid'))
            ->toThrow(ValueError::class);
    });
});
