<?php

use App\Enums\EventStatus;
use App\Models\Event;

describe('Enum Consistency', function () {
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

});
