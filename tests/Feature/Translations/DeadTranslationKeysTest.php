<?php

use Illuminate\Support\Facades\File;

describe('dead translation keys removal', function () {
    it('has no debriefing keys in attendance domain', function () {
        $attendanceEn = base_path('lang/en/attendance.php');

        expect(File::exists($attendanceEn))->toBeTrue('attendance.php translation file should exist');

        $translations = include $attendanceEn;

        // After M028 cleanup, debriefing-related keys should not be in attendance domain
        $debriefingKeys = array_filter(array_keys($translations), fn (string $key) => str_contains($key, 'debrief'));

        expect($debriefingKeys)->toBeEmpty('attendance.php should not contain debriefing keys');
    });

    it('has no debriefing keys in attendance domain for German', function () {
        $attendanceDe = base_path('lang/de/attendance.php');

        if (! File::exists($attendanceDe)) {
            // German file may not exist yet — skip gracefully
            expect(true)->toBeTrue();

            return;
        }

        $translations = include $attendanceDe;

        $debriefingKeys = array_filter(array_keys($translations), fn (string $key) => str_contains($key, 'debrief'));

        expect($debriefingKeys)->toBeEmpty('de/attendance.php should not contain debriefing keys');
    });
});
