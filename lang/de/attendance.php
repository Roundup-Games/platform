<?php

return [
    // Stufen-Bezeichnungen
    'label_tier_reliable' => 'Zuverlässig',
    'label_tier_active' => 'Aktiv',
    'label_tier_newcomer' => 'Neuling',
    'content_tier_description_reliable' => 'Konstante Anwesenheit über 5+ Spiele.',
    'content_tier_description_active' => 'Regelmäßiger Spieler mit Verbesserungspotenzial.',
    'content_tier_description_newcomer' => 'Neu auf der Plattform — spiele weiter, um deine Historie aufzubauen.',

    // Statistiken
    'label_stat_games_played' => 'Spiele gespielt',
    'label_stat_attendance_rate' => 'Anwesenheitsrate',
    'label_stat_no_show_count' => 'Nicht-Erscheinen',
    'label_stat_attended_count' => 'Teilgenommen',
    'label_stat_late_cancel_count' => 'Späte Absagen',
    'label_stat_excused_count' => 'Entschuldigt',
    'label_stat_cancelled_early_count' => 'Frühzeitig abgesagt',
    'label_stat_value_percent' => ':value %',
    'label_stat_value_count' => ':count',

    // Warteliste
    'content_waitlist_position' => 'Du bist #:position auf der Warteliste.',
    'content_waitlist_spot_opened' => 'Ein Platz ist frei geworden!',
    'action_waitlist_confirm' => 'Platz bestätigen',
    'action_waitlist_decline' => 'Platz ablehnen',
    'content_waitlist_expired' => 'Wartelistenplatz abgelaufen.',
    'action_waitlist_join' => 'Warteliste beitreten',
    'content_waitlist_full' => 'Dieses Spiel ist voll. Tritt der Warteliste bei, um benachrichtigt zu werden, wenn ein Platz frei wird.',
    'content_waitlist_confirmed' => 'Du hast deinen Platz bestätigt!',
    'content_waitlist_declined' => 'Du hast den Platz abgelehnt.',
    'content_waitlist_added' => 'Du wurdest zur Warteliste hinzugefügt.',
    'content_waitlist_deadline' => 'Bestätige vor :deadline.',
    'label_waitlist_management' => 'Warteliste',
    'content_waitlist_no_players' => 'Keine Spieler auf der Warteliste.',

    // Bank
    'label_bench_on_the_bench' => 'Auf der Bank',
    'content_bench_description' => 'Diese Spieler sitzen auf der Bank. Befördere sie, wenn ein Platz frei wird.',
    'flash_bench_promoted' => 'Spieler wurde von der Bank befördert.',
    'action_bench_promote' => 'Befördern',
    'content_bench_placed' => 'Die Sitzung ist voll — du wurdest auf die Bank gesetzt.',
    'content_bench_you_are_on' => 'Du sitzt auf der Bank',

    // Anwesenheitsaktionen & Statusbezeichnungen
    'action_report_attendance' => 'Anwesenheit melden',
    'action_dispute_report' => 'Einspruch erheben',
    'status_attended' => 'Teilgenommen',
    'status_no_show' => 'Nicht erschienen',
    'status_late_cancel' => 'Späte Absage',
    'status_excused' => 'Entschuldigt',
    'status_cancelled_early' => 'Frühzeitig abgesagt',
    'status_pending' => 'Ausstehend',
    'status_not_reported' => 'Noch nicht gemeldet',
    'label_attendance' => 'Anwesenheit',
    'label_attendance_status' => 'Anwesenheitsstatus',
    'label_reported_by' => 'Gemeldet von :name',
    'label_reported_at' => 'Gemeldet :time',
    'flash_attendance_reported' => 'Anwesenheit erfolgreich gemeldet.',
    'flash_attendance_disputed' => 'Dein Einspruch wurde zur Prüfung eingereicht.',
    'flash_dispute_resolved' => 'Einspruch geklärt — Anwesenheit aktualisiert.',
    'flash_dispute_upheld' => 'Einspruch geprüft — Meldung beibehalten.',

    // Einspruch
    'heading_dispute_title' => 'Anwesenheitsmeldung anfechten',
    'content_dispute_description' => 'Wenn du glaubst, dass diese Anwesenheitsmeldung falsch ist, kannst du einen Einspruch mit deiner Begründung einreichen.',
    'placeholder_dispute_reason' => 'Erkläre, warum du dieser Meldung widersprichst...',
    'action_dispute_submit' => 'Einspruch einreichen',

    // Nachbericht
    'heading_recap_title' => 'Host-Nachbericht',
    'content_recap_by' => 'Geschrieben von :host',
    'action_recap_write' => 'Nachbericht verfassen',
    'content_recap_posted' => 'Nachbericht veröffentlicht',
    'content_recap_activity' => 'schrieb einen Nachbericht für',
    'content_recap_none' => 'Es wurde noch kein Nachbericht verfasst.',

    // Dashboard-Engagement
    'dashboard_games_this_week' => 'Spiele diese Woche',
    'dashboard_attended' => 'teilgenommen',
    'dashboard_pending' => 'ausstehend',
    'dashboard_total' => 'gesamt',
    'dashboard_hosting' => 'Host',
    'dashboard_no_games_this_week' => 'Keine Spiele diese Woche geplant. Zeit, dein nächstes Abenteuer zu finden!',
    'dashboard_find_next_game' => 'Nächstes Spiel finden',
    'dashboard_new_recaps' => 'Neue Nachberichte',
    'dashboard_recap_by' => 'Von :name',
    'dashboard_attendance_summary' => ':attended teilgenommen, :pending ausstehend — :total Spiele diese Woche',

    // Warnung bei später Absage
    'content_warning_late_cancel' => 'Du stornierst weniger als 24 Stunden vor dem Spiel. Dies wird als späte Absage erfasst.',
    'content_warning_below_min_players' => 'Dieses Spiel hat jetzt weniger als die erforderliche Mindestanzahl an Spielern.',

    // Host-Zuverlässigkeitspräferenz
    'field_reliability_preference' => 'Anwesenheitspräferenz',
    'hint_reliability_preference' => 'Optional: Bevorzuge Spieler mit einer Mindest-Anwesenheitsrate (%). Dies ist eine weiche Präferenz, kein harter Filter.',
    'content_host_prefers_attendance' => 'Host bevorzugt ≥:percent% Anwesenheit',

    // Autorisierungsfehler
    'error_dispute_unauthorized' => 'Du bist nicht berechtigt, diesen Anwesenheitsbericht anzufechten.',
];
