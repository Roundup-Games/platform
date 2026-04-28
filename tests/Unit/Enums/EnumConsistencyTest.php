<?php

use App\Enums\EventStatus;
use App\Enums\PaymentStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\RegistrationStatus;
use App\Enums\Visibility;
use App\Models\Event;

describe('Enum Consistency', function () {
    it('EventStatus has all expected cases matching codebase constants', function () {
        $expected = [
            'draft', 'published', 'registration_open', 'registration_closed',
            'in_progress', 'completed', 'cancelled',
        ];

        expect(EventStatus::values())->toBe($expected);
    });

    it('RegistrationStatus has all expected cases matching codebase usage', function () {
        $expected = ['pending', 'confirmed', 'cancelled', 'waitlisted'];

        expect(RegistrationStatus::values())->toBe($expected);
    });

    it('PaymentStatus has all expected cases matching codebase usage', function () {
        $expected = ['pending', 'paid', 'not_required', 'refunded', 'failed'];

        expect(PaymentStatus::values())->toBe($expected);
    });

    it('ParticipantRole has all expected cases matching codebase usage', function () {
        $expected = ['owner', 'player', 'invited', 'applicant'];

        expect(ParticipantRole::values())->toBe($expected);
    });

    it('ParticipantStatus has all expected cases matching codebase usage', function () {
        $expected = ['approved', 'rejected', 'pending', 'waitlisted', 'benched'];

        expect(ParticipantStatus::values())->toBe($expected);
    });

    it('Visibility has all expected cases matching codebase usage', function () {
        $expected = ['public', 'protected', 'private'];

        expect(Visibility::values())->toBe($expected);
    });

    it('EventStatus matches Event::VALID_TRANSITIONS keys', function () {
        $eventStatusValues = EventStatus::values();
        $transitionKeys = array_keys(Event::VALID_TRANSITIONS);

        expect($transitionKeys)->toBe($eventStatusValues);
    });

    it('EventStatus transition targets are valid statuses', function () {
        $validValues = EventStatus::values();

        foreach (Event::VALID_TRANSITIONS as $from => $targets) {
            foreach ($targets as $target) {
                expect($target)->toBeIn($validValues, "Transition target '{$target}' from '{$from}' is not a valid EventStatus value");
            }
        }
    });

    it('all enums are backed by string type', function () {
        $enums = [
            EventStatus::class,
            RegistrationStatus::class,
            PaymentStatus::class,
            ParticipantRole::class,
            ParticipantStatus::class,
            Visibility::class,
        ];

        foreach ($enums as $enumClass) {
            $reflection = new ReflectionEnum($enumClass);
            expect($reflection->getBackingType()?->getName())->toBe('string');
        }
    });

    it('all enums provide a values() method returning flat string array', function () {
        $enums = [
            EventStatus::class,
            RegistrationStatus::class,
            PaymentStatus::class,
            ParticipantRole::class,
            ParticipantStatus::class,
            Visibility::class,
        ];

        foreach ($enums as $enumClass) {
            $values = $enumClass::values();
            expect($values)->toBeArray();
            foreach ($values as $value) {
                expect($value)->toBeString();
            }
            expect($values)->toHaveCount(count($enumClass::cases()));
        }
    });
});
