<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Attendance Reporting Window
    |--------------------------------------------------------------------------
    |
    | Duration (in hours) of the attendance reporting window after a game's
    | scheduled end time. During this window, all reports carry full weight
    | regardless of when they are filed — timeliness no longer affects weight.
    |
    */
    'reporting_window_hours' => env('ATTENDANCE_REPORTING_WINDOW_HOURS', 72),

    /*
    |--------------------------------------------------------------------------
    | Auto-Complete Offset
    |--------------------------------------------------------------------------
    |
    | Hours after a game's scheduled end time before the system auto-completes
    | it. At auto-completion, unresolved attendance entries are resolved by
    | consensus among the reports filed within the reporting window.
    |
    */
    'auto_complete_offset_hours' => env('ATTENDANCE_AUTO_COMPLETE_OFFSET_HOURS', 12),

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
    | the corresponding weight multiplier. Under the consensus system,
    | all reports within the reporting window carry full weight — only
    | low-reliability reporters have their weight scaled down.
    |
    */
    'low_reliability_threshold' => env('ATTENDANCE_LOW_RELIABILITY_THRESHOLD', 50.0),
    'low_reliability_multiplier' => env('ATTENDANCE_LOW_RELIABILITY_MULTIPLIER', 0.5),

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

    /*
    |--------------------------------------------------------------------------
    | Consensus Thresholds
    |--------------------------------------------------------------------------
    |
    | participation_threshold: Minimum ratio of non-self participants who must
    |   file a report before consensus can be reached.
    |
    | no_show_majority: Minimum ratio of filed reports that must be no_show
    |   for the consensus to resolve as a no-show.
    |
    | host_no_show_weight: Penalty weight applied when the host no-shows
    |   their own game. Negative value means it counts against the host.
    |
    */
    'participation_threshold' => env('ATTENDANCE_PARTICIPATION_THRESHOLD', 0.5),
    'no_show_majority' => env('ATTENDANCE_NO_SHOW_MAJORITY', 0.5),
    'host_no_show_weight' => env('ATTENDANCE_HOST_NO_SHOW_WEIGHT', -1.5),

];
