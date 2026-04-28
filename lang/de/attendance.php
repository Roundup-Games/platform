<?php

return [
    // Stufen-Bezeichnungen
    'tier_reliable' => 'Zuverlässig',
    'tier_active' => 'Aktiv',
    'tier_newcomer' => 'Neuling',
    'tier_description_reliable' => 'Konstante Anwesenheit über 5+ Spiele.',
    'tier_description_active' => 'Regelmäßiger Spieler mit Verbesserungspotenzial.',
    'tier_description_newcomer' => 'Neu auf der Plattform — spiele weiter, um deine Historie aufzubauen.',

    // Statistiken
    'stat_games_played' => 'Spiele gespielt',
    'stat_attendance_rate' => 'Anwesenheitsrate',
    'stat_no_show_count' => 'Nicht-Erscheinen',
    'stat_attended_count' => 'Teilgenommen',
    'stat_late_cancel_count' => 'Späte Absagen',
    'stat_excused_count' => 'Entschuldigt',
    'stat_value_percent' => ':value %',
    'stat_value_count' => ':count',

    // Warteliste
    'waitlist_position' => 'Du bist #:position auf der Warteliste.',
    'waitlist_spot_opened' => 'Ein Platz ist frei geworden!',
    'waitlist_confirm' => 'Platz bestätigen',
    'waitlist_decline' => 'Platz ablehnen',
    'waitlist_expired' => 'Wartelistenplatz abgelaufen.',
    'waitlist_join' => 'Warteliste beitreten',
    'waitlist_full' => 'Dieses Spiel ist voll. Tritt der Warteliste bei, um benachrichtigt zu werden, wenn ein Platz frei wird.',
    'waitlist_confirmed' => 'Du hast deinen Platz bestätigt!',
    'waitlist_declined' => 'Du hast den Platz abgelehnt.',
    'waitlist_added' => 'Du wurdest zur Warteliste hinzugefügt.',
    'waitlist_deadline' => 'Bestätige vor :deadline.',
    'waitlist_management' => 'Warteliste',
    'waitlist_no_players' => 'Keine Spieler auf der Warteliste.',

    // Bank
    'bench_on_the_bench' => 'Auf der Bank',
    'bench_description' => 'Diese Spieler sitzen auf der Bank. Befördere sie, wenn ein Platz frei wird.',
    'bench_promoted' => 'Spieler wurde von der Bank befördert.',
    'bench_promote' => 'Befördern',
    'bench_placed' => 'Die Sitzung ist voll — du wurdest auf die Bank gesetzt.',
    'bench_you_are_on' => 'Du sitzt auf der Bank',

    // Anwesenheitsaktionen & Statusbezeichnungen
    'action_report_attendance' => 'Anwesenheit melden',
    'action_dispute_report' => 'Einspruch erheben',
    'status_attended' => 'Teilgenommen',
    'status_no_show' => 'Nicht erschienen',
    'status_late_cancel' => 'Späte Absage',
    'status_excused' => 'Entschuldigt',
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
    'dispute_title' => 'Anwesenheitsmeldung anfechten',
    'dispute_description' => 'Wenn du glaubst, dass diese Anwesenheitsmeldung falsch ist, kannst du einen Einspruch mit deiner Begründung einreichen.',
    'dispute_reason_placeholder' => 'Erkläre, warum du dieser Meldung widersprichst...',
    'dispute_submit' => 'Einspruch einreichen',

    // Debriefing
    'debriefing_title' => 'Sitzungs-Debriefing',
    'debriefing_summary_title' => 'Gruppen-Debriefing',
    'debriefing_description' => 'Nimm dir einen Moment Zeit, um über diese Sitzung nachzudenken. Deine Antworten helfen allen, das Erlebnis zu verbessern.',
    'debriefing_submit' => 'Debriefing absenden',
    'debriefing_submitted' => 'Dein Debriefing wurde eingereicht. Danke für deine Reflexion!',
    'debriefing_waiting' => 'Warte auf die Debriefing-Antworten der Teilnehmer.',
    'debriefing_responses' => '{1}1 Antwort|[2,*]:count Antworten',
    'debriefing_confidential' => 'vertraulich — nur für den Host sichtbar',
    'debriefing_prompt_what_went_well' => 'Was lief gut?',
    'debriefing_prompt_what_to_change' => 'Etwas für das nächste Mal ändern?',
    'debriefing_prompt_safety_concerns' => 'Sicherheitsbedenken?',
    'debriefing_prompt_star' => 'Vergib einen Stern — etwas Positives über die Sitzung',
    'debriefing_prompt_wish' => 'Ein Wunsch — etwas für das nächste Mal',
    'debriefing_tool_debriefing' => 'Debriefing',
    'debriefing_tool_stars_and_wishes' => 'Sterne & Wünsche',

    // Nachbericht
    'recap_title' => 'Host-Nachbericht',
    'recap_by' => 'Geschrieben von :host',
    'recap_write' => 'Nachbericht verfassen',
    'recap_posted' => 'Nachbericht veröffentlicht',
    'recap_activity' => 'schrieb einen Nachbericht für',
    'recap_none' => 'Es wurde noch kein Nachbericht verfasst.',

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
    'warning_late_cancel' => 'Du stornierst weniger als 24 Stunden vor dem Spiel. Dies wird als späte Absage erfasst.',
    'warning_below_min_players' => 'Dieses Spiel hat jetzt weniger als die erforderliche Mindestanzahl an Spielern.',

    // Host-Zuverlässigkeitspräferenz
    'field_reliability_preference' => 'Anwesenheitspräferenz',
    'hint_reliability_preference' => 'Optional: Bevorzuge Spieler mit einer Mindest-Anwesenheitsrate (%). Dies ist eine weiche Präferenz, kein harter Filter.',
    'host_prefers_attendance' => 'Host bevorzugt ≥:percent% Anwesenheit',
];
