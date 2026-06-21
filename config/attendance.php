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
    | Minimum number of distinct game sessions with UNCORROBORATED reports in
    | the lookback window before a reporter is quarantined.
    |
    | Only reports filed against games that resolved by EarlyConsensus count.
    | EarlyConsensus means every approved participant filed an attendance report,
    | so a still-uncorroborated report there is a genuine outlier (your peers
    | reported and none agreed with you). Reports against games that resolved by
    | Timeout (the session auto-closed because not enough people bothered to
    | report) or Manual are EXCLUDED — absence of corroboration in a
    | low-engagement session says nothing about the reporter and must not be
    | punished. Prod data: ~11,375 timeout vs ~1 early_consensus resolution,
    | so this filter keeps the quarantine dormant until engagement grows.
    |
    | Set to 0 to DISABLE the volume quarantine entirely (quarantineThreshold()
    | returns 0 and checkGriefResistance skips the volume check).
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
    | Player Late-Cancel Threshold
    |--------------------------------------------------------------------------
    |
    | Hours before game/session time within which a player's self-cancellation
    | is marked as LateCancel (counts against reliability) rather than
    | CancelledEarly (neutral). Distinct from host_cancel_late_hours above,
    | which governs the host cancellation offence — the two rules may diverge.
    |
    */
    'player_late_cancel_hours' => env('ATTENDANCE_PLAYER_LATE_CANCEL_HOURS', 24),

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
