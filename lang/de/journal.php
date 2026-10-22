<?php

// Kampagnen-Journal (M071). Flache, präfixierte Snake-Case-Keys gemäß der
// i18n-Namenskonvention (php artisan i18n:check). type_* und visibility_*
// spiegeln App\Enums\JournalEntryKind / JournalEntryVisibility (type_* folgt
// dem GameType-Enum-Präzedenzfall); die Texte des Rundenabschluss-Composers
// (title_composer/content_composer_description/label_composer_placeholder/
// action_composer_submit) stammen aus S01 — spätere Slices ergänzen den Journal-Tab.
// status_* spiegelt App\Enums\GoalStatus und type_progression/type_item/
// type_condition/type_note spiegeln App\Enums\CharacterStateChangeType (M071/S02).

return [
    'type_recap' => 'Recap',
    'type_in_character' => 'In-Charakter',
    'type_prep_note' => 'GM-Vorbereitung',
    'visibility_players' => 'Spieler',
    'visibility_gms' => 'GMs',
    'error_not_campaign_session' => 'Ein Journal-Recap kann nur für eine Kampagnen-Runde verfasst werden.',
    'error_recap_too_long' => 'Das Recap darf 10.000 Zeichen nicht überschreiten.',
    'title_composer' => 'Recap im Journal verfassen',
    'content_composer_description' => 'Dein Recap wird Teil des Kampagnen-Journals — teile mit, was in dieser Runde passiert ist.',
    'label_composer_placeholder' => 'Teile mit, was in dieser Runde passiert ist…',
    'action_composer_submit' => 'Ins Journal veröffentlichen',
    'status_planned' => 'Geplant',
    'status_active' => 'Aktiv',
    'status_achieved' => 'Erreicht',
    'status_failed' => 'Gescheitert',
    'status_abandoned' => 'Aufgegeben',
    'type_progression' => 'Fortschritt',
    'type_item' => 'Gegenstand',
    'type_condition' => 'Zustand',
    'type_note' => 'Notiz',
    'error_not_participant' => 'Nur Kampagnen-Teilnehmende können diesen Journal-Inhalt verfassen.',
    'error_participant_not_active' => 'Nur bestätigte Teilnehmende und Teilnehmende auf der Ersatzbank können In-Charakter-Einträge verfassen.',
    'error_participant_not_in_campaign' => 'Diese Person nimmt nicht an dieser Kampagne teil.',
    'error_participant_not_seated' => 'Statusänderungen können nur für bestätigte Teilnehmende und Teilnehmende auf der Ersatzbank protokolliert werden.',
    'error_rate_limited' => 'Du schreibst zu schnell. Versuche es in :minutes Minuten wieder.',
    'error_not_campaign_owner' => 'Das kann nur der GM der Kampagne.',
    'error_body_empty' => 'Der Journal-Eintrag darf nicht leer sein.',
    'error_body_too_long' => 'Journal-Einträge dürfen 10.000 Zeichen nicht überschreiten.',
    'error_in_game_date_too_long' => 'Das In-Game-Datum darf 100 Zeichen nicht überschreiten.',
    'error_title_required' => 'Ein Ziel braucht einen Titel.',
    'error_title_too_long' => 'Ziel-Titel dürfen 200 Zeichen nicht überschreiten.',
    'error_description_too_long' => 'Ziel-Beschreibungen dürfen 1.000 Zeichen nicht überschreiten.',
    'error_delta_empty' => 'Die Zustandsänderung darf nicht leer sein.',
    'error_delta_too_long' => 'Zustandsänderungen dürfen 2.000 Zeichen nicht überschreiten.',
    'tab_overview' => 'Übersicht',
    'tab_journal' => 'Journal',
    'filter_all' => 'Alle',
    'filter_recap' => 'Recaps',
    'filter_in_character' => 'In-Charakter',
    'filter_prep_note' => 'GM-Notizen',
    'empty_journal' => 'Noch keine Journal-Einträge.',
    'action_newer_entries' => 'Neuere',
    'action_older_entries' => 'Ältere',
    'label_filter_group' => 'Journal-Einträge filtern',
    'label_unknown_author' => 'Ehemaliges Mitglied',
    'heading_goals' => 'Kampagnen-Ziele',
    'heading_state_changes' => 'Charakter-Zustände',
    'label_goal_title' => 'Ziel-Titel',
    'label_goal_description' => 'Beschreibung (optional)',
    'label_state_participant' => 'Charakter',
    'label_state_choose_participant' => 'Charakter wählen',
    'label_state_change_type' => 'Art der Änderung',
    'label_state_choose_type' => 'Art wählen',
    'label_state_delta' => 'Zustandsänderung',
    'label_in_game_date' => 'In-Game-Datum (optional)',
    'action_add_goal' => 'Ziel hinzufügen',
    'action_change_status' => 'Status aktualisieren',
    'action_post_in_character' => 'In-Charakter veröffentlichen',
    'action_post_prep_note' => 'Vorbereitung speichern',
    'action_log_state' => 'Zustandsänderung erfassen',
    'action_show_all' => 'Alle anzeigen',
    'action_load_earlier_changes' => 'Frühere Änderungen laden',
    'label_history_capped' => 'Es werden die letzten :count älteren Änderungen angezeigt.',
    'empty_goals' => 'Noch keine Ziele.',
    'empty_state_changes' => 'Noch keine Zustandsänderungen.',
    'hint_prep_note' => 'Nur für GMs sichtbar — Spieler sehen diese Notizen nie.',
    'flash_in_character_posted' => 'Ins Journal veröffentlicht.',
    'flash_prep_note_posted' => 'Vorbereitungsnotiz gespeichert.',
    'flash_goal_created' => 'Ziel hinzugefügt.',
    'flash_goal_status_changed' => 'Ziel-Status aktualisiert.',
    'flash_state_change_logged' => 'Zustandsänderung erfasst.',

    // Previously-On-Kompositionstexte (M071/S04, Spec §3.1): die
    // Nur-Text-Abschnitte des Digests und die Publish-Guards. :session ist
    // die Anzahl abgeschlossener Runden; Runde-Terminologie durchgängig
    // (K001: „Session“ ist als Lehnwort nur bei echtem Präzisionsbedarf
    // erlaubt — der Digest-Titel braucht ihn nicht, und veröffentlichte
    // Digests sind unveränderlich).
    'title_previously_on' => 'Zuvor in :campaign – Runde :session',
    'section_goals_moved' => 'Geänderte Ziele',
    'section_loose_threads' => 'Offene Fäden',
    'section_next_session' => 'Nächste Runde',
    'error_draft_already_published' => 'Dieser Rückblick wurde bereits veröffentlicht.',
    'error_session_not_publishable' => 'Rückblicke können nur für eine anstehende Kampagnen-Runde veröffentlicht werden.',

    // Previously-On-Panel (M071/S04-T03): die Prüf- und Veröffentlichungs-
    // oberfläche für GMs im Journal-Tab — Entwurfsvorschau, One-Click-
    // Veröffentlichung, Mitglieder-Gate, Bestätigung. Du-Form gemäß K001.
    'title_previously_on_panel' => 'Zuvor in der Kampagne',
    'content_previously_on_description' => 'Ein Rückblick deiner neuesten Journal-Ereignisse, zusammengestellt für die nächste Runde. Prüfe ihn, bevor du ihn mit deiner Gruppe teilst.',
    'action_publish_previously_on' => 'Rückblick veröffentlichen',
    'flash_previously_on_published' => 'Der Rückblick wurde auf der Runden-Pinnwand veröffentlicht.',
    'error_previously_on_not_available' => 'Der Rückblick ist derzeit nicht verfügbar.',
    'label_previously_on_target_session' => 'Für die Runde',
    'label_previously_on_published_at' => 'Veröffentlicht am :date',
    'label_previously_on_composed_at' => 'Erstellt am :date',
    'action_refresh_previously_on' => 'Entwurf aktualisieren',
    'flash_previously_on_refreshed' => 'Entwurf aus dem Journal aktualisiert.',
    'content_previously_on_audience' => 'Wird an alle bestätigten Teilnehmenden dieser Runde zugestellt, auch an einmalige Gäste.',
    'title_previously_on_gate' => '„Zuvor in der Kampagne“ ist eine Mitglieder-Funktion',
    'content_previously_on_gate' => 'GM-Mitglieder erhalten vor jeder Runde automatisch einen Rückblick — Recap, Ziele und offene Fäden aus deinem Kampagnen-Journal. Sieh dir die Mitgliedschaft an, um ihn freizuschalten.',
    'action_view_membership' => 'Mitgliedschaft ansehen',

    // Rekrutierungszusammenfassung (M071/S05, Spec §4): die Entwurfszeilen
    // und Schreib-Guards für den spoiler-sicheren „Bisher in der Kampagne“-
    // Block. :count ist die Anzahl abgeschlossener Runden; :themes trägt
    // die Tonfall-/Themen-Chips der Charta (gelabelt über die vorhandenen
    // charter.*-Keys). Runde-Terminologie und Du-Form gemäß K001.
    'content_recruitment_sessions_played' => ':count gespielte Runden',
    'content_recruitment_tone_themes' => 'Tonfall & Themen: :themes',
    'error_recruitment_unauthorized' => 'Nur der GM der Kampagne kann die Rekrutierungszusammenfassung verwalten.',
    'error_recruitment_checklist_incomplete' => 'Bestätige jede Spoiler-Sicherheitsprüfung, bevor du die Rekrutierungszusammenfassung anwendest.',
    'error_recruitment_draft_required' => 'Die Rekrutierungszusammenfassung darf nicht leer sein.',
    'error_recruitment_draft_too_long' => 'Die Rekrutierungszusammenfassung darf 10.000 Zeichen nicht überschreiten.',

    // Rekrutierungs-Composer (M071/S05-T03, Spec §4.1/§4.2): der Composer
    // des GMs im Journal-Tab — Spoiler-Checkliste, Ziel-/Auszugs-Auswahl,
    // der editierbare Entwurf und die Besucher-Vorschau im Composer (die
    // echte öffentliche Seite leitet angemeldete Nutzer um). :count ist
    // die Anzahl neuer/seitiger Runden; :session das Runde-Datum des
    // Auszugs-Labels. Du-Form gemäß K001.
    'action_recruit_with_campaign' => 'Mit dieser Kampagne rekrutieren',
    'action_apply_recruitment_summary' => 'Auf der Kampagnenseite veröffentlichen',
    'action_remove_recruitment_summary' => 'Von der Kampagnenseite entfernen',
    'action_copy_recruitment_summary' => 'Entwurf kopieren',
    'action_redact_selection' => 'Auswahl schwärzen',
    'action_preview_as_visitor' => 'Als Besucher ansehen',
    'action_append_excerpt' => 'Auszug hinzufügen',
    'action_copy_public_link' => 'Öffentlichen Link kopieren',
    'heading_recruitment_composer' => 'Rekrutierungszusammenfassung',
    'section_recruitment_checklist' => 'Spoiler-Sicherheitsprüfung',
    'section_recruitment_goals' => 'Ziele, die du teilen möchtest',
    'section_recruitment_excerpts' => 'Recap-Auszüge',
    'label_recruitment_draft' => 'Dein „Bisher in der Kampagne“-Entwurf',
    'label_recruitment_excerpt' => 'Von der Runde am :session',
    'confirmation_recruitment_review_excerpts' => 'Ich habe jeden Auszug auf Spoiler geprüft.',
    'confirmation_recruitment_names_removed' => 'Alle Spielernamen und identifizierenden Details sind entfernt.',
    'confirmation_recruitment_visitor_preview' => 'Ich habe die Besucher-Vorschau geprüft — genau das sollen Fremde lesen.',
    'hint_recruitment_public' => 'Das wird öffentlich. Besucher, die über den Beitritt nachdenken, lesen diesen Text.',
    'hint_recruitment_open_seats' => 'Deine Kampagne ist öffentlich und hat freie Plätze — eine Zusammenfassung hilft beim Rekrutieren.',
    'hint_recruitment_stale' => 'Seit der letzten Aktualisierung deiner Zusammenfassung wurden :count neue Runden gespielt.',
    'empty_recruitment_no_goals' => 'Noch keine aktiven Ziele.',
    'empty_recruitment_no_recaps' => 'Noch keine Recap-Einträge.',
    'flash_recruitment_applied' => 'Der „Bisher in der Kampagne“-Block ist auf deiner Kampagnenseite live.',
    'flash_recruitment_removed' => 'Der „Bisher in der Kampagne“-Block wurde von deiner Kampagnenseite entfernt.',
];
