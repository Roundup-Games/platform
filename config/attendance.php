<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auto-Attendance Threshold
    |--------------------------------------------------------------------------
    |
    | Hours after game completion before auto-attend kicks in.
    | Players who haven't confirmed or disputed their attendance by this
    | time are automatically marked as attended.
    |
    */
    'auto_attend_hours' => env('ATTENDANCE_AUTO_ATTEND_HOURS', 48),

    /*
    |--------------------------------------------------------------------------
    | Report Timeliness
    |--------------------------------------------------------------------------
    |
    | Hours after game before report weight starts decaying.
    | Reports filed after this threshold receive a reduced weight.
    |
    */
    'timeliness_threshold_hours' => env('ATTENDANCE_TIMELINESS_HOURS', 72),

    /*
    |--------------------------------------------------------------------------
    | Quarantine Thresholds
    |--------------------------------------------------------------------------
    |
    | Maximum uncorroborated reports in the lookback window before quarantine.
    |
    */
    'quarantine_threshold' => env('ATTENDANCE_QUARANTINE_THRESHOLD', 3),
    'quarantine_lookback_days' => env('ATTENDANCE_QUARANTINE_LOOKBACK_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Reliability & Report Weights
    |--------------------------------------------------------------------------
    |
    | Reliability score below which reporter weight is reduced, and
    | the corresponding weight multiplier. Late reports also get reduced.
    |
    */
    'low_reliability_threshold' => env('ATTENDANCE_LOW_RELIABILITY_THRESHOLD', 50.0),
    'low_reliability_multiplier' => env('ATTENDANCE_LOW_RELIABILITY_MULTIPLIER', 0.5),
    'late_report_multiplier' => env('ATTENDANCE_LATE_REPORT_MULTIPLIER', 0.7),

    /*
    |--------------------------------------------------------------------------
    | Host Cancellation Penalties
    |--------------------------------------------------------------------------
    |
    | Maximum players for a game to count as "small" for host cancel penalty,
    | and hours before game time for cancellation to be considered "late".
    |
    */
    'host_cancel_min_roster' => env('ATTENDANCE_HOST_CANCEL_MIN_ROSTER', 1),
    'host_cancel_late_hours' => env('ATTENDANCE_HOST_CANCEL_LATE_HOURS', 24),

];
