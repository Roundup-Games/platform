<?php

return [
    // Stufen-Bezeichnungen
    'label_tier_reliable' => 'Zuverlässig',
    'label_tier_active' => 'Aktiv',
    'label_tier_newcomer' => 'Neu dabei',
    'content_tier_description_reliable' => 'Du warst schon bei über 5 Spielen dabei — weiter so!',
    'content_tier_active' => 'Du spielst regelmäßig, es geht bergauf.',
    'content_tier_description_newcomer' => 'Neu hier — je öfter du mitspielst, desto besser kennen dich die anderen.',

    // Statistiken
    'label_stat_games_played' => 'Spiele gespielt',
    'label_stat_attendance_rate' => 'Anwesenheitsrate',
    'label_stat_no_show_count' => 'Nicht erschienen',
    'label_stat_attended_count' => 'Teilgenommen',
    'label_stat_late_cancel_count' => 'Kurzfristig abgesagt',
    'label_stat_excused_count' => 'Entschuldigt',
    'label_stat_cancelled_early_count' => 'Frühzeitig abgesagt',
    'label_stat_value_percent' => ':value%',
    'label_stat_value_count' => ':count',

    // Warteliste
    'content_waitlist_position' => 'Du bist auf Platz #:position der Warteliste.',
    'content_waitlist_spot_opened' => 'Ein Platz ist frei geworden!',
    'action_waitlist_confirm' => 'Platz bestätigen',
    'action_waitlist_decline' => 'Platz ablehnen',
    'content_waitlist_expired' => 'Dein Wartelistenplatz ist abgelaufen.',
    'action_waitlist_join' => 'Auf die Warteliste',
    'content_waitlist_full' => 'Dieses Spiel ist voll. Setz dich auf die Warteliste, dann bekommst du Bescheid, wenn etwas frei wird.',
    'content_waitlist_confirmed' => 'Du hast deinen Platz bestätigt!',
    'content_waitlist_declined' => 'Du hast den Platz abgelehnt.',
    'content_waitlist_added' => 'Du bist jetzt auf der Warteliste.',
    'content_waitlist_deadline' => 'Bitte bestätige bis :deadline.',
    'label_waitlist_management' => 'Warteliste',
    'content_waitlist_no_players' => 'Niemand auf der Warteliste.',

    // Ersatzbank
    'label_bench_on_the_bench' => 'Auf der Ersatzbank',
    'content_bench_description' => 'Diese Spieler sitzen auf der Ersatzbank. Du kannst sie reinholen, wenn ein Platz frei wird.',
    'flash_bench_promoted' => 'Spieler wurde von der Ersatzbank geholt.',
    'action_bench_promote' => 'Reinholen',
    'content_bench_placed' => 'Das Spiel ist voll — du sitzt erst mal auf der Ersatzbank.',
    'content_bench_you_are_on' => 'Du sitzt auf der Ersatzbank',

    // Anwesenheit
    'action_report_attendance' => 'Anwesenheit melden',
    'action_dispute_report' => 'Meldung anfechten',
    'status_attended' => 'Teilgenommen',
    'status_no_show' => 'Nicht erschienen',
    'status_late_cancel' => 'Kurzfristig abgesagt',
    'status_excused' => 'Entschuldigt',
    'status_cancelled_early' => 'Frühzeitig abgesagt',
    'status_pending' => 'Ausstehend',
    'status_not_reported' => 'Noch nicht gemeldet',
    'label_attendance' => 'Anwesenheit',
    'label_attendance_status' => 'Anwesenheit',
    'label_reported_by' => 'Gemeldet von :name',
    'label_reported_at' => 'Gemeldet :time',
    'flash_attendance_reported' => 'Anwesenheit wurde gemeldet.',
    'flash_attendance_disputed' => 'Deine Anfechtung wird geprüft.',
    'flash_dispute_resolved' => 'Anfechtung geklärt — Anwesenheit wurde aktualisiert.',
    'flash_dispute_upheld' => 'Anfechtung geprüft — Meldung bleibt bestehen.',

    // Anfechtung
    'heading_dispute_title' => 'Anwesenheitsmeldung anfechten',
    'content_dispute_description' => 'Wenn diese Meldung nicht stimmt, kannst du hier beschreiben, warum.',
    'placeholder_dispute_reason' => 'Erkläre, warum du anderer Meinung bist…',
    'action_dispute_submit' => 'Anfechtung absenden',

    // Zusammenfassung (game-detail.blade.php)
    'heading_recap_title' => 'Zusammenfassung des Hosts',
    'content_recap_by' => 'Von :host',
    'action_recap_write' => 'Zusammenfassung schreiben',
    'content_recap_posted' => 'Zusammenfassung veröffentlicht',
    'content_recap_activity' => 'hat eine Zusammenfassung geschrieben für',
    'content_recap_none' => 'Noch keine Zusammenfassung.',

    // Dashboard (dashboard.blade.php)
    'dashboard_games_this_week' => 'Deine Spiele diese Woche',
    'dashboard_attended' => 'teilgenommen',
    'dashboard_pending' => 'ausstehend',
    'dashboard_total' => 'gesamt',
    'dashboard_hosting' => 'Du hostest',
    'dashboard_no_games_this_week' => 'Keine Spiele diese Woche. Zeit, was Neues zu finden!',
    'dashboard_find_next_game' => 'Spiel finden',
    'dashboard_new_recaps' => 'Neue Zusammenfassungen',
    'dashboard_recap_by' => 'Von :name',
    'dashboard_attendance_summary' => ':attended teilgenommen, :pending ausstehend — :total Spiele diese Woche',

    // Kurzfristige Absage
    'content_warning_late_cancel' => 'Du sagst weniger als 24 Stunden vorher ab. Das wird als kurzfristige Absage gespeichert.',
    'content_warning_below_min_players' => 'Jetzt sind weniger Spieler drin als gebraucht werden.',

    // Anwesenheitspräferenz beim Erstellen
    'field_reliability_preference' => 'Bevorzugte Anwesenheit',
    'hint_reliability_preference' => 'Du kannst Spieler bevorzugen, die eine bestimmte Anwesenheitsrate haben (%). Das ist nur ein Pluspunkt, kein Ausschlusskriterium.',
    'content_host_prefers_attendance' => 'Der Host bevorzugt ≥:percent% Anwesenheit',

    // Autorisierungsfehler
    'error_dispute_unauthorized' => 'Du darfst diese Meldung nicht anfechten.',
];
