<?php

// Kampagnen-Charta (M070). Flache, präfixierte Snake-Case-Keys gemäß der
// i18n-Namenskonvention (php artisan i18n:check). Dynamische Lookups bauen
// Keys aus den DTO-Konstanten: section_{id}_title/_short/_description,
// field_{section}_{field}_label/_help/_placeholder, label_{field}_{value}
// sowie die Vokabular-Maps label_theme_*/label_boundary_*/label_etiquette_*/
// label_comms_* — Spiegel der App\Dto\CharterContent-Konstanten.

return [
    // Seitentitel / Composer-Überschrift
    'title_charter' => 'Kampagnen-Charta',

    // Einstiegskarte auf der Kampagnenseite (nur für dich sichtbar)
    'title_entry' => 'Kampagnen-Charta',
    'description_entry_teaser' => 'Erwartungen, Grenzen und Safety-Tools klären, bevor die erste Runde startet — und die Unterschrift aller einholen.',
    'action_create_charter' => 'Charta erstellen',
    'action_continue_charter' => 'Charta weiterbearbeiten',
    'action_view_charter' => 'Charta ansehen',
    'label_draft_badge' => 'Entwurf',
    'label_version_badge' => 'v:version',

    // Status-Labels
    'status_draft' => 'Entwurf',
    'status_active' => 'Veröffentlicht',
    'status_archived' => 'Archiviert',

    // Startbildschirm (noch keine Charta vorhanden)
    'heading_start' => 'Starte deine Kampagnen-Charta',
    'description_start_intro' => 'Eine Charta bündelt alles, was vor der ersten Runde zählt: Worum geht es, wo liegen die Grenzen, welche Safety-Tools nutzt ihr — und worauf verpflichtet sich alle. Deine Spieler lesen sie und unterschreiben.',
    'title_quickstart' => 'Schnellstart',
    'description_quickstart' => 'Aus deiner Kampagne vorbelegt — du kannst alles anpassen, bevor du veröffentlichst.',
    'title_blank' => 'Leere Charta',
    'description_blank' => 'Ganz von vorn anfangen und jede Entscheidung selbst treffen.',

    // Die acht Abschnitte: Titel, kurzes Step-Label, Beschreibung
    'section_overview_title' => 'Kampagnenüberblick',
    'section_overview_short' => 'Überblick',
    'section_overview_description' => 'Worum es in dieser Kampagne geht: Pitch, System, Tonfall, Themen und Tischgröße.',
    'section_boundaries_title' => 'Inhaltliche Grenzen',
    'section_boundaries_short' => 'Grenzen',
    'section_boundaries_description' => 'Der Tisch-Standard für Lines (hartes Nein) und Veils (ausblenden). Deine Spieler ergänzen ihre privaten Antworten beim Unterschreiben.',
    'section_safety_title' => 'Safety-Tools',
    'section_safety_short' => 'Safety',
    'section_safety_description' => 'Die Safety-Tools dieses Tisches, wie hier signalisiert wird und wie oft ihr sie auffrischt.',
    'section_policies_title' => 'Tischregeln',
    'section_policies_short' => 'Regeln',
    'section_policies_description' => 'PvP, Charaktertod, Entwicklung, Loot, Homebrew und Tisch-Etikette.',
    'section_ai_tools_title' => 'KI & digitale Tools',
    'section_ai_tools_short' => 'KI & Digital',
    'section_ai_tools_description' => 'Einwilligungsregeln für KI-Inhalte, Transkription, Würfel, Zettel, Fotos und Aufnahmen.',
    'section_logistics_title' => 'Logistik & Zusagen',
    'section_logistics_short' => 'Logistik',
    'section_logistics_description' => 'Spielort, Essen, Rundenrhythmus, Rundendauer, Anwesenheit und euer Kommunikationskanal.',
    'section_character_title' => 'Charaktererstellung',
    'section_character_short' => 'Charaktere',
    'section_character_description' => 'Bau-Regeln und welche Art von Hintergrundgeschichten du dir wünschst.',
    'section_agreement_title' => 'Die Vereinbarung',
    'section_agreement_short' => 'Vereinbarung',
    'section_agreement_description' => 'Prüfe deine Charta und veröffentliche sie. Deine Spieler bestätigen beim Unterschreiben die Aussagen unten.',

    // Felder — Überblick
    'field_overview_pitch_label' => 'Pitch',
    'field_overview_pitch_help' => 'Worum geht es in dieser Kampagne? Hol deine Spieler ab.',
    'field_overview_pitch_placeholder' => 'z.B. Eine Noir-Intrigen-Kampagne in der Stadt Schwelle — drei Fraktionen, ein Heist, jeder schuldet jemandem etwas.',
    'field_overview_system_notes_label' => 'System, Edition & erlaubte Quellen',
    'field_overview_system_notes_help' => 'Welches System spielt ihr, und welche Bücher oder Quellen sind erlaubt?',
    'field_overview_system_notes_placeholder' => 'z.B. D&D 5E (Regeln 2024), nur PHB + XGE.',
    'field_overview_tone_label' => 'Tonfall',
    'field_overview_tone_help' => 'Stelle für jede Achse ein, wo die Kampagne steht.',
    'field_overview_tone_serious_lighthearted_label' => 'Ernst ↔ Unbeschwert',
    'field_overview_tone_serious_lighthearted_low' => 'Ernst',
    'field_overview_tone_serious_lighthearted_high' => 'Unbeschwert',
    'field_overview_tone_gritty_heroic_label' => 'Grimmig ↔ Heroisch',
    'field_overview_tone_gritty_heroic_low' => 'Grimmig',
    'field_overview_tone_gritty_heroic_high' => 'Heroisch',
    'field_overview_tone_political_dungeon_label' => 'Politisch ↔ Dungeon-Crawl',
    'field_overview_tone_political_dungeon_low' => 'Politisch',
    'field_overview_tone_political_dungeon_high' => 'Dungeon-Crawl',
    'field_overview_themes_label' => 'Themen',
    'field_overview_themes_help' => 'Wähle die Themen, mit denen Spieler rechnen sollten.',
    'field_overview_expected_shape_label' => 'Voraussichtlicher Umfang',
    'field_overview_expected_shape_help' => 'Wie lange soll die Kampagne laufen?',
    'field_overview_table_size_label' => 'Tischgröße',
    'field_overview_table_size_help' => 'Wie viele Spieler passen an diesen Tisch?',
    'field_overview_may_add_players_label' => 'Wir dürfen später Spieler aufnehmen',
    'field_overview_may_add_players_help' => 'Aktiviere das, wenn die Runde während der Kampagne wachsen kann.',

    // Felder — Grenzen
    'field_boundaries_lines_label' => 'Lines am Tisch — hartes Nein',
    'field_boundaries_lines_help' => 'Themen, die an diesem Tisch grundsätzlich nicht vorkommen.',
    'field_boundaries_lines_categories_label' => 'Kategorien',
    'field_boundaries_lines_text_label' => 'In unseren eigenen Worten',
    'field_boundaries_lines_placeholder' => 'z.B. Keine detaillierten Folterszenen; Gewalt gegen Kinder bleibt komplett außen vor.',
    'field_boundaries_veils_label' => 'Veils am Tisch — ausblenden',
    'field_boundaries_veils_help' => 'Themen, die vorkommen dürfen, aber off-screen bleiben oder ausgeblendet werden.',
    'field_boundaries_veils_categories_label' => 'Kategorien',
    'field_boundaries_veils_text_label' => 'In unseren eigenen Worten',
    'field_boundaries_veils_placeholder' => 'z.B. Romantik wird ausgeblendet; Substanzkonsum wird erzählt, nie ausgespielt.',
    'field_boundaries_intro_note_label' => 'Einleitung für deine Spieler',
    'field_boundaries_intro_note_help' => 'Wie du Grenzen an deinem Tisch rahmen möchtest.',
    'field_boundaries_intro_note_placeholder' => 'z.B. Grenzen sind keine Schwäche — sie halten den Tisch für alle angenehm. Verschärfen kannst du deine jederzeit.',

    // Felder — Safety
    'field_safety_tools_label' => 'Genutzte Tools',
    'field_safety_tools_help' => 'Wähle mindestens eines — das sind die Praktiken, auf die sich dieser Tisch einlässt.',
    'field_safety_custom_note_label' => 'Eigene Tool-Notiz',
    'field_safety_custom_note_help' => 'Beschreibe alles Eigene, das ihr zusätzlich zu den Tools oben nutzt.',
    'field_safety_custom_note_placeholder' => 'z.B. Wir machen zu Beginn jeder Runde einen Zwei-Minuten-Stimmungscheck.',
    'field_safety_signal_how_label' => 'Wie man an diesem Tisch signalisiert',
    'field_safety_signal_how_help' => 'Wie pausiert oder markiert jemand Inhalte hier?',
    'field_safety_signal_how_placeholder' => 'z.B. Hand heben oder das Wort „Pause“ fallen lassen — jeder darf, jederzeit, ohne Begründung.',
    'field_safety_revisit_label' => 'Auffrisch-Rhythmus',
    'field_safety_revisit_help' => 'Wie oft sprecht ihr Grenzen und Tools durch?',
    'field_safety_open_door_label' => 'Open Door gilt',
    'field_safety_open_door_help' => 'Jeder darf jederzeit ohne Fragen den Tisch verlassen.',

    // Felder — Tischregeln
    'field_policies_pvp_label' => 'Spieler gegen Spieler (PvP)',
    'field_policies_pvp_help' => 'Dürfen Charaktere gegeneinander arbeiten?',
    'field_policies_character_death_label' => 'Charaktertod',
    'field_policies_character_death_help' => 'Wie funktioniert der Tod von Charakteren hier?',
    'field_policies_advancement_label' => 'Entwicklung',
    'field_policies_advancement_help' => 'Wie steigen Charaktere auf?',
    'field_policies_loot_policy_label' => 'Loot-&-Schatz-Regel',
    'field_policies_loot_policy_help' => 'Optional — wie geht ihr mit Beute um?',
    'field_policies_loot_policy_placeholder' => 'z.B. Loot wird im Konsens verteilt; Items gehen an den, dem die Gruppe am meisten nützt.',
    'field_policies_homebrew_label' => 'Homebrew & Third-Party-Inhalte',
    'field_policies_homebrew_help' => 'Welche eigenen Inhalte sind erlaubt?',
    'field_policies_etiquette_label' => 'Tisch-Etikette',
    'field_policies_etiquette_help' => 'Wähle die Grundregeln, die an deinem Tisch zählen.',
    'field_policies_etiquette_text_label' => 'Weitere Etikette in unseren eigenen Worten',
    'field_policies_etiquette_text_help' => 'Alles, was die Auswahl oben nicht abdeckt.',
    'field_policies_etiquette_text_placeholder' => 'z.B. Wir machen alle 90 Minuten eine 10-minütige Pause.',

    // Felder — KI & digitale Tools
    'field_ai_tools_ai_prep_label' => 'KI-generierte Inhalte in der Vorbereitung',
    'field_ai_tools_ai_prep_help' => 'Porträts, Handout-Grafiken, Atmosphäre, NPC-Texte, Homebrew, Recap.',
    'field_ai_tools_ai_transcription_label' => 'KI-Notizen & Transkription',
    'field_ai_tools_ai_transcription_help' => 'Transkription erfasst die Stimme aller — sie braucht das Einverständnis des ganzen Tisches.',
    'field_ai_tools_dice_label' => 'Würfel',
    'field_ai_tools_dice_help' => 'Was darf an diesem Tisch geworfen werden?',
    'field_ai_tools_sheets_label' => 'Charakterblätter & Nachschlagewerke',
    'field_ai_tools_sheets_help' => 'Papier oder digital?',
    'field_ai_tools_photography_label' => 'Fotos vom Tisch & Miniaturen',
    'field_ai_tools_photography_help' => 'Fotos zeigen andere Menschen — behandele sie wie Einwilligungsfragen.',
    'field_ai_tools_recording_label' => 'Audio-/Videoaufnahmen',
    'field_ai_tools_recording_help' => 'Wird irgendetwas aufgenommen?',
    'field_ai_tools_custom_clause_label' => 'Eigene Klausel',
    'field_ai_tools_custom_clause_help' => 'Optional — formuliere deine eigene Tooling-Regel.',
    'field_ai_tools_custom_clause_placeholder' => 'z.B. Musik über Playlists ist okay; keine geklonten Charakterstimmen.',

    // Felder — Logistik
    'field_logistics_venue_mode_label' => 'Spielort',
    'field_logistics_venue_mode_help' => 'Spielt ihr an einem festen Ort oder wechselt ihr?',
    'field_logistics_location_label' => 'Treffpunkt',
    'field_logistics_location_help' => 'Wo trefft ihr euch?',
    'field_logistics_venue_access_notes_label' => 'Barrierefreiheit & Hinweise zum Spielort',
    'field_logistics_venue_access_notes_help' => 'Stufenloser Zugang, ruhige Bereiche, Regeln des Lokals — alles, was Spielern bei der Planung hilft.',
    'field_logistics_venue_access_notes_placeholder' => 'z.B. Raum im Erdgeschoss, stufenloser Eingang. Das Café bittet uns, je ein Getränk zu kaufen.',
    'field_logistics_food_drink_label' => 'Essen & Trinken',
    'field_logistics_food_drink_help' => 'Wie läuft Essen bei euren Runden?',
    'field_logistics_travel_notes_label' => 'Anfahrt & Ankunft',
    'field_logistics_travel_notes_help' => 'Parken, ÖPNV, Ankunftszeit.',
    'field_logistics_travel_notes_placeholder' => 'z.B. Parken ab 18 Uhr frei; U-Bahn Ausgang B, 2 Minuten. Bitte 10 Minuten früher da sein.',
    'field_logistics_cadence_label' => 'Rundenrhythmus',
    'field_logistics_cadence_help' => 'Wie oft trefft ihr euch?',
    'field_logistics_session_length_label' => 'Rundendauer',
    'field_logistics_session_length_help' => 'Wie lang ist eine typische Runde?',
    'field_logistics_session_length_other_label' => 'Andere Rundendauer',
    'field_logistics_session_length_other_help' => 'Beschreibe eure typische Rundenlänge.',
    'field_logistics_session_length_other_placeholder' => 'z.B. Etwa 5 Stunden mit Essenspause.',
    'field_logistics_attendance_note_label' => 'Anwesenheitserwartung',
    'field_logistics_attendance_note_help' => 'Was erwartest du von der Anwesenheit deiner Spieler?',
    'field_logistics_attendance_note_placeholder' => 'z.B. Wir spielen auch, wenn ein Spieler fehlt; bitte Status frühzeitig aktualisieren.',
    'field_logistics_max_missed_sessions_label' => 'Grenze für Fehlrunden',
    'field_logistics_max_missed_sessions_help' => 'Wer mehr als diese Anzahl Runden in Folge verpasst, kann seinen Platz verlieren. Leer lassen für kein Limit.',
    'field_logistics_absence_notice_label' => 'Absage-Frist',
    'field_logistics_absence_notice_help' => 'Wie viel Vorlauf brauchst du bei einer Absage?',
    'field_logistics_missing_player_label' => 'Wenn ein Spieler fehlt',
    'field_logistics_missing_player_help' => 'Was passiert mit der Runde — und mit dem Charakter?',
    'field_logistics_comms_label' => 'Kommunikationskanal',
    'field_logistics_comms_help' => 'Die eine digitale Ebene des Tisches — wählt, was für euch funktioniert.',
    'field_logistics_comms_other_label' => 'Anderer Kanal',
    'field_logistics_comms_other_help' => 'Nennt euer vereinbartes Medium.',
    'field_logistics_comms_other_placeholder' => 'z.B. Signal-Gruppe',

    // Felder — Charaktere
    'field_character_build_constraints_label' => 'Bau-Regeln',
    'field_character_build_constraints_help' => 'Level, Methode, erlaubte Bücher, Gruppenzusammensetzung.',
    'field_character_build_constraints_placeholder' => 'z.B. Start auf Stufe 1, Standard-Array oder Punktesystem, nur Grundregelwerk. Die Gruppe braucht mindestens eine heilende Rolle.',
    'field_character_backstory_requested_label' => 'Hintergrund-Hooks erwünscht',
    'field_character_backstory_requested_help' => 'Bitte Spieler, Hintergrund-Hooks mitzubringen, die du in die Handlung einweben kannst.',
    'field_character_backstory_guidance_label' => 'Hinweise zur Hintergrundgeschichte',
    'field_character_backstory_guidance_help' => 'Welche Art von Geschichten? Wie lang, wie dunkel, wie verknüpft?',
    'field_character_backstory_guidance_placeholder' => 'z.B. Maximal drei Absätze, eine offene Frage für mich, eine Verbindung zu einem anderen Charakter.',

    // Labels für Auswahlmöglichkeiten: label_{feld}_{wert}
    'label_expected_shape_short_arc' => 'Kurzer Bogen (3–6 Runden)',
    'label_expected_shape_ongoing' => 'Fortlaufende Kampagne',
    'label_expected_shape_open_ended' => 'Ohne festes Ende',
    'label_revisit_each_session' => 'Check-in in jeder Runde',
    'label_revisit_end_of_arc' => 'Am Ende jedes Handlungsbogens',
    'label_revisit_on_request' => 'Auf Anfrage',
    'label_pvp_none' => 'Gar nicht',
    'label_pvp_limited' => 'Begrenzt (mit Einverständnis, Szene für Szene)',
    'label_pvp_open' => 'Frei',
    'label_character_death_possible' => 'Möglich',
    'label_character_death_gm_may_fudge' => 'GM darf Ergebnisse anpassen',
    'label_character_death_player_consent' => 'Nur mit Einverständnis der Spielerin oder des Spielers',
    'label_advancement_milestone' => 'Meilensteine',
    'label_advancement_xp' => 'Klassische EP',
    'label_advancement_narrative' => 'Erzählerisch',
    'label_homebrew_no' => 'Nein',
    'label_homebrew_gm_approval' => 'Mit GM-Freigabe',
    'label_homebrew_free' => 'Frei',
    'label_ai_prep_welcome_disclosed' => 'Willkommen, bei Offenlegung',
    'label_ai_prep_case_by_case' => 'Im Einzelfall abstimmen',
    'label_ai_prep_not_at_table' => 'Nicht an diesem Tisch',
    'label_ai_transcription_not_at_table' => 'Nicht am Tisch',
    'label_ai_transcription_gm_private' => 'Nur private GM-Notizen',
    'label_ai_transcription_shared_table' => 'Mit dem Tisch geteilt',
    'label_dice_physical' => 'Physische Würfel an diesem Tisch',
    'label_dice_apps_fine' => 'Würfel-Apps okay',
    'label_dice_whatever_fun' => 'Was Spaß macht',
    'label_sheets_paper_preferred' => 'Papier bevorzugt',
    'label_sheets_digital_fine' => 'Digital okay',
    'label_sheets_either' => 'Beides okay',
    'label_photography_ask_every_time' => 'Jedes Mal fragen',
    'label_photography_preapproved_table_only' => 'Vorab genehmigt, nur innerhalb des Tisches',
    'label_photography_no_photos' => 'Keine Fotos',
    'label_recording_none' => 'Keine',
    'label_recording_gm_private' => 'Nur private GM-Notizen',
    'label_recording_shared_table' => 'Mit dem Tisch geteilt',
    'label_venue_mode_location' => 'Fester Spielort',
    'label_venue_mode_rotate' => 'Wir wechseln die Spielorte',
    'label_food_drink_venue_rules' => 'Die Regeln des Spielorts gelten',
    'label_food_drink_snack_rotation' => 'Snack-Rotation',
    'label_food_drink_byo' => 'Jeder bringt selbst mit',
    'label_food_drink_group_orders' => 'Gemeinsame Bestellungen',
    'label_cadence_weekly' => 'Wöchentlich',
    'label_cadence_biweekly' => 'Alle zwei Wochen',
    'label_cadence_monthly' => 'Monatlich',
    'label_cadence_irregular' => 'Unregelmäßig',
    'label_session_length_2h' => '2 Stunden',
    'label_session_length_3h' => '3 Stunden',
    'label_session_length_4h' => '4 Stunden',
    'label_session_length_other' => 'Andere',
    'label_absence_notice_asap' => 'So früh wie möglich',
    'label_absence_notice_24h' => '24 Stunden vorher',
    'label_absence_notice_48h' => '48 Stunden vorher',
    'label_missing_player_skip_session' => 'Runde fällt aus',
    'label_missing_player_pc_as_npc' => 'Charakter wird als NPC weitergespielt',
    'label_missing_player_side_oneshot' => 'Stattdessen kleiner One-Shot',

    // Vokabular-Chips — label_theme_* / label_boundary_* / label_etiquette_* / label_comms_*
    // als Spiegel der CharterContent-Konstanten THEMES / BOUNDARY_CATEGORIES / ETIQUETTE / COMMS.
    'label_theme_politics' => 'Politik',
    'label_theme_horror' => 'Horror',
    'label_theme_intrigue' => 'Intrigen',
    'label_theme_war' => 'Krieg',
    'label_theme_mystery' => 'Mystery',
    'label_theme_exploration' => 'Erkundung',
    'label_theme_heist' => 'Heist',
    'label_theme_romance' => 'Romantik',
    'label_theme_survival' => 'Überleben',
    'label_theme_found_family' => 'Gefundene Familie',
    'label_theme_sandbox' => 'Sandbox',
    'label_boundary_violence' => 'Gewalt',
    'label_boundary_sexual_content' => 'Sexueller Inhalt',
    'label_boundary_harm_to_children' => 'Gewalt gegen Kinder',
    'label_boundary_substance_use' => 'Substanzkonsum',
    'label_boundary_bigotry' => 'Diskriminierung',
    'label_boundary_phobias' => 'Phobien',
    'label_boundary_self_harm' => 'Selbstverletzung & Suizid',
    'label_boundary_animal_harm' => 'Tierquälerei',
    'label_boundary_real_world_trauma' => 'Reale Traumata',
    'label_etiquette_phones_away' => 'Handys weg vom Tisch',
    'label_etiquette_gm_rules_final' => 'GM-Entscheidungen sind final',
    'label_etiquette_no_split_party' => 'Gruppe bleibt zusammen',
    'label_etiquette_stay_in_character' => 'In der Rolle bleiben',
    'label_etiquette_prep_expected' => 'Vorbereitung wird erwartet',
    'label_etiquette_respect_time' => 'Start- und Endzeiten respektieren',
    'label_comms_platform' => 'Plattform-Nachrichten',
    'label_comms_discord' => 'Discord',
    'label_comms_whatsapp' => 'WhatsApp',
    'label_comms_telegram' => 'Telegram',
    'label_comms_other' => 'Anderes vereinbartes Medium',

    // Stepper-Texte
    'label_step_of' => 'Schritt :step von :total',
    'status_completed' => 'Fertig',
    'status_incomplete' => 'Offen',
    'placeholder_choose_option' => 'Bitte wählen…',

    // Aktionen
    'action_start_quickstart' => 'Mit Schnellstart beginnen',
    'action_start_blank' => 'Leer beginnen',
    'action_save_draft' => 'Entwurf speichern',
    'action_next' => 'Weiter',
    'action_back' => 'Zurück',
    'action_publish' => 'Charta veröffentlichen',
    'action_continue_editing' => 'Weiter bearbeiten',
    'action_copy_link' => 'Link kopieren',

    // Entwurfszustand
    'content_draft_banner' => 'Diese Charta ist ein Entwurf — bis zur Veröffentlichung siehst sie nur du.',
    'flash_draft_saved' => 'Entwurf gespeichert.',

    // Veröffentlichung erfolgreich
    'flash_publish_success' => 'Charta veröffentlicht!',
    'description_publish_success' => 'Deine Charta ist online. Teile den Link mit deinen Spielern, damit sie sie lesen und unterschreiben können.',
    'label_share_link' => 'Teilbarer Link',
    'flash_version_published' => 'Version :version veröffentlicht',

    // Öffentliche, schreibgeschützte Ansicht
    'view_description_by_gm' => 'Kampagnen-Charta deines Game Masters',
    'content_version' => 'Version :version',
    'content_published_on' => 'Veröffentlicht am :date',
    'view_draft_banner' => 'Das ist ein Entwurf — diese Charta ist noch nicht veröffentlicht.',
    'content_archived_note' => 'Diese Charta wurde am Ende der Kampagne archiviert.',
    'content_gm_strip_published' => 'Version :version veröffentlicht am :date',
    'view_agreement_intro' => 'Wenn deine Spieler unterschreiben, bestätigen sie diese Aussagen:',

    // Lifecycle-Schutzregeln
    'error_only_draft_editable' => 'Bearbeitet werden kann nur eine Charta, die noch im Entwurf ist.',
    'error_already_published' => 'Diese Charta wurde bereits veröffentlicht.',
    'error_charter_orphaned' => 'Diese Charta gehört nicht mehr zu einer Kampagne — sie kann nicht veröffentlicht werden.',

    // Schutzregeln für Unterschrift & Gegenzeichnung (M070/S02)
    'error_charter_not_active' => 'Diese Charta ist noch nicht veröffentlicht — sie kann noch nicht unterschrieben werden.',
    'error_not_participant' => 'Nur zugelassene Teilnehmer dieser Kampagne können die Charta unterschreiben.',
    'error_not_owner' => 'Nur der Kampagnen-Inhaber kann die Charta gegenzeichnen.',
    'error_already_signed' => 'Du hast diese Version der Charta bereits unterschrieben.',
    'error_already_countersigned' => 'Diese Charta-Version wurde bereits gegengezeichnet.',
    'error_countersign_pending_signatures' => ':count zugelassene Teilnehmer haben die Charta noch nicht unterschrieben.',

    // Änderungs-Guards (M070/S03)
    'error_only_active_amendable' => 'Geändert werden kann nur eine veröffentlichte Charta — Entwürfe werden veröffentlicht, archivierte Chartas sind fixierte Geschichte.',
    'error_amended_concurrently' => 'Diese Charta wurde gleichzeitig anderweitig geändert — bitte neu laden und erneut versuchen.',

    // §8 Die Vereinbarung — S02 nutzt diese Keys unverändert für die Unterschrifts-Checkboxen
    'confirm_read_attestation' => 'Ich habe diese Charta vollständig gelesen.',
    'confirm_safety' => 'Ich stimme den Safety-Tools zu, die dieser Tisch vereinbart hat, und respektiere die Grenzen aller.',
    'confirm_policies' => 'Ich stimme den Tischregeln oben zu.',
    'confirm_ai_tools' => 'Ich stimme der Regelung zu KI- und digitalen Tools an diesem Tisch zu und lege offen, wenn ich selbst KI-Unterstützung in unser Spiel bringe.',
    'confirm_logistics' => 'Ich verpflichte mich, die Termin-, Spielort- und Kommunikationsvereinbarungen nach Kräften einzuhalten, und gebe Bescheid, wenn das Leben dazwischenkommt.',

    // Unterschrifts-Panel + §2 private Antworten (M070/S02-T03). Die fünf
    // Checkboxen nutzen die confirm_-Keys oben UNVERÄNDERT; diese Keys decken
    // die Panel-Texte, die privaten Antwortfelder und die Unterschrifts-Karte ab.
    'heading_signature_panel' => 'Diese Charta unterschreiben',
    'description_signature_panel_intro' => 'Bestätige alle fünf Aussagen mit einem Häkchen und unterschreibe danach. Deine Unterschrift ist an Version :version gebunden.',
    'description_privacy_note' => 'Private Antworten sehen nur du und der GM — niemals andere Teilnehmer.',
    'action_sign_charter' => 'Charta unterschreiben',
    'flash_signed_success' => 'Charta unterschrieben — danke dir!',
    'heading_private_answers' => 'Deine privaten Antworten',
    'description_private_answers_intro' => 'Ergänze deine eigenen Lines und Veils zum Tisch-Standard oben. Der GM teilt sie nie mit dem Tisch.',
    'field_my_lines_label' => 'Meine Lines — hartes Nein',
    'field_my_lines_help' => 'Themen, die für dich nie an diesen Tisch gehören.',
    'field_my_lines_text_label' => 'Meine Lines in eigenen Worten',
    'field_my_veils_label' => 'Meine Veils — ausblenden',
    'field_my_veils_help' => 'Themen, die okay sind, aber für dich off-screen bleiben sollen.',
    'field_my_veils_text_label' => 'Meine Veils in eigenen Worten',
    'field_note_to_gm_label' => 'Notiz an deinen GM',
    'field_note_to_gm_help' => 'Alles, was dein GM vor der ersten Runde noch wissen sollte.',
    'heading_signed_card' => 'Unterschrieben',
    'content_signed_card' => 'Du hast Charta v:version am :date unterschrieben.',
    'content_signed_no_answers' => 'Du hast keine privaten Antworten hinterlegt.',

    // GM-Roster + Gegenzeichnung (M070/S02-T04). Die Roster-Texte sieht nur
    // der Inhaber; die Gegenzeichnungszeile unter §8 sieht jeder, sobald sie
    // existiert.
    'heading_gm_roster' => 'Unterschriftenliste',
    'label_roster_signed_summary' => ':signed von :total unterschrieben',
    'label_roster_signed_chip' => 'Unterschrieben — v:version am :date',
    'label_roster_pending_chip' => 'Noch offen',
    'label_roster_reagreement_pending' => 'Erneute Zustimmung ausstehend',
    'action_roster_answers' => 'Private Antworten ansehen',
    'label_roster_no_answers' => 'Keine privaten Antworten hinterlegt.',
    'label_roster_not_signed' => 'Hat noch nicht unterschrieben.',
    'label_roster_answer_lines' => 'Lines — hartes Nein',
    'label_roster_answer_veils' => 'Veils — ausblenden',
    'label_roster_answer_note' => 'Notiz an den GM',
    'description_roster_privacy' => 'Private Antworten sehen nur du und der jeweilige Teilnehmer — bearbeiten lassen sie sich nie.',
    'action_countersign_charter' => 'Charta gegenzeichnen',
    'description_countersign_pending' => ':count zugelassene Teilnehmer haben noch nicht unterschrieben.',
    'content_gm_strip_countersigned' => 'Gegengezeichnet — v:version am :date',
    'flash_countersigned_success' => 'Charta gegengezeichnet.',
    'content_countersigned_line' => 'Gegengezeichnet von :gm am :date',

    // Änderungs-Diff (M070/S03) — gemeinsam für die GM-Vorschau im Review-
    // Schritt und die Re-Agreement-Ansicht der Teilnehmer; Feldbezeichnungen
    // nutzen die bestehenden field_{section}_{field}_label-Keys, Werte label_{value}.
    'heading_diff_changes' => 'Was hat sich geändert',
    'content_diff_empty' => 'Keine Änderungen gegenüber der veröffentlichten Version.',
    'label_diff_changed' => 'Geändert',
    'label_diff_added' => 'Hinzugefügt',
    'label_diff_removed' => 'Entfernt',
    'label_diff_items_added' => 'Neu hinzugekommen',
    'label_diff_items_removed' => 'Entfällt',
    'label_diff_old' => 'Vorher',
    'label_diff_new' => 'Nachher',

    // Änderungsmodus des Charta-Composers (M070/S03) — der GM-Amendment-Flow
    // plus der Einstieg im veröffentlichten Panel. Die Diff-Keys oben teilen
    // sich GM-Vorschau und Teilnehmer-Re-Agreement (T04/T05).
    'action_amend_charter' => 'Charta ändern',
    'action_cancel_amendment' => 'Änderung abbrechen',
    'action_publish_amendment' => 'Änderung veröffentlichen',
    'label_amendment_in_progress' => 'Du änderst die veröffentlichte Charta — deine Änderungen greifen erst, wenn du die neue Version veröffentlichst.',
    'label_amendment_material_badge' => 'Wesentliche Änderung',
    'label_amendment_minor_badge' => 'Geringfügige Änderung',
    'description_amendment_material' => 'Wesentliche Änderungen setzen jede Unterschrift — die deiner Spieler und deine Gegenzeichnung — wieder auf ausstehende Zustimmung zurück.',
    'description_amendment_minor' => 'Geringfügige Änderungen übernehmen alle bestehenden Unterschriften — niemand muss erneut unterschreiben.',
    'field_amendment_changelog_label' => 'Änderungsprotokoll',
    'field_amendment_changelog_placeholder' => 'z. B. Die Lines zu Gewalt nach dem Session-Zero-Gespräch präzisiert.',
    'description_amendment_changelog' => 'Pflichtfeld. Fasse zusammen, was sich geändert hat und warum — es wird mit der neuen Version gespeichert und deinen Spielern zusammen mit der Übersicht der Änderungen angezeigt.',
    'flash_amendment_published' => 'Änderung als Version :version veröffentlicht.',

    // Re-Agreement-Banner für Teilnehmer (M070/S03-T04) — eingeblendet auf
    // der veröffentlichten Charta für zugelassene Teilnehmer, deren
    // Unterschrift eine wesentliche Änderung auf ausstehende Zustimmung
    // zurückgesetzt hat.
    'content_amended_banner_title' => 'Diese Charta wurde geändert',
    'content_amended_banner_on' => 'Geändert am :date',
    'content_amended_banner_body' => 'Der GM hat eine wesentliche Änderung veröffentlicht — deine Unterschrift braucht daher erneut deine Zustimmung. Sieh dir die Änderungen an und unterschreibe anschließend die neue Version unten.',
    'action_show_changes' => 'Änderungen anzeigen',
    'label_amendment_changelog_shown' => 'Änderungsprotokoll des GM',

    // Kostenloses Limit, Archivieren und das höfliche Upsell (M070/S04) — R084:
    // Die Obergrenze gleichzeitig aktiver Chartas ist die einzige
    // Mitgliedschaftsprüfung im gesamten Lebenszyklus; Mitglieder sind
    // unbegrenzt (R085).
    'error_charter_limit_reached' => 'Du hast bereits :limit aktive Chartas — das Limit des kostenlosen Plans. Archiviere eine beendete Charta, um einen Platz freizugeben, oder schalte unbegrenzte Chartas mit der Mitgliedschaft frei.',
    'error_only_active_archivable' => 'Nur eine veröffentlichte Charta lässt sich archivieren.',
    'action_archive_charter' => 'Charta archivieren',
    'confirm_archive_charter' => 'Diese Charta archivieren? Sie verlässt die aktive Liste und gibt einen Charta-Platz frei — Unterschriften und Versionen bleiben erhalten.',
    'flash_charter_archived' => 'Charta archiviert.',
    'label_active_charter_limit' => 'Kostenloser Plan: :limit aktive Chartas',
    'content_upsell_limit_title' => 'Deine Charta-Plätze sind alle belegt',
    'content_upsell_limit_body' => 'Der kostenlose Plan umfasst :limit gleichzeitig aktive Kampagnen-Chartas. Archiviere eine beendete Charta, um einen Platz freizugeben — oder schalte unbegrenzte Chartas und alle weiteren GM-Komfortfunktionen mit der Mitgliedschaft frei.',
    'content_upsell_limit_view_pricing' => 'Preise ansehen',
    'content_upsell_limit_membership' => 'Mehr zur Mitgliedschaft',

    // Gespeicherte Vorlagen & Start aus früherer Charta (M070/S04, R085) —
    // Mitgliedschafts-Komfortfunktionen: additiver Mehrwert, für kostenlose
    // GMs komplett ausgeblendet.
    'heading_start_from_template' => 'Charta wiederverwenden',
    'title_template_card' => 'Aus einer gespeicherten Vorlage starten',
    'title_previous_card' => 'Aus einer früheren Charta starten',
    'action_start_from_template' => 'Aus Vorlage starten',
    'action_start_from_previous' => 'Aus früherer Charta starten',
    'action_save_as_template' => 'Als Vorlage speichern',
    'field_template_name' => 'Name der Vorlage',
    'flash_template_saved' => 'Vorlage gespeichert.',
    'error_templates_member_only' => 'Gespeicherte Charta-Vorlagen sind eine der Komfortfunktionen der Mitgliedschaft.',
    'error_template_not_yours' => 'Diese Vorlage existiert nicht oder gehört dir nicht.',
    'error_previous_not_yours' => 'Diese Charta existiert nicht oder gehört dir nicht.',
    'label_template_empty' => 'Noch nichts hier — speichere eine Charta als Vorlage oder veröffentliche eine, dann kannst du sie hier wiederverwenden.',

    // Charta-Status im GM-Workspace (M070/S04-T03, Konzept §4) — eine
    // Zeile pro eigener Charta: Unterschriftenfortschritt, offene
    // Unterschriften und die Gegengezeichnet-Spur.
    'heading_workspace_charters' => 'Charta-Status',
    'label_percent_signed' => ':percent% unterschrieben',
    'label_pending_count' => ':count offen',
    'label_days_outstanding' => 'Seit :count Tag offen|Seit :count Tagen offen',
    'label_no_participants' => 'Noch keine Teilnehmer',
    'content_workspace_empty' => 'Noch keine Chartas — starte eine über die Seite deiner Kampagne.',

    // Charta-Prüfexport (M070/S04-T04, R085) — die dritte
    // Mitgliedschafts-Komfortfunktion: der unveränderliche Versions- und
    // Unterschriftenverlauf, druckfertig aufbereitet für Cons, Clubs und
    // venue-Regeln. Private Grenzen-Antworten sind niemals Teil des Exports
    // (Konzept §2).
    'title_audit_export' => 'Charta-Prüfexport',
    'heading_version_history' => 'Versionsverlauf',
    'heading_signature_history' => 'Unterschriftenverlauf',
    'action_print_export' => 'Drucken / Als PDF speichern',
    'action_export_audit' => 'Prüfexport erstellen',
    'label_audit_privacy_note' => 'Private Grenzen-Antworten sind niemals Teil dieses Exports — sie bleiben zwischen dir und jedem Teilnehmer.',
    'content_upsell_audit' => 'Ein druckfertiger Export des kompletten Prüfverlaufs deiner Charta — jede veröffentlichte Version mit ihrem Änderungsprotokoll und jede Unterschrift mit Zeitstempel — ist eine der Komfortfunktionen der Mitgliedschaft.',
    'label_audit_not_published' => 'Diese Charta wurde noch nicht veröffentlicht — die Prüfhistorie beginnt mit der ersten veröffentlichten Version.',

    // Prüfexport-Tabellen-Chrome: Spaltenköpfe und Rollen-Chips. Status-Chips
    // nutzen heading_signed_card / label_roster_reagreement_pending weiter;
    // der Änderungstyp-Chip nutzt die Material-/Geringfügig-Badges.
    'label_audit_col_version' => 'Version',
    'label_audit_col_published' => 'Veröffentlicht',
    'label_audit_col_change' => 'Änderung',
    'label_audit_col_participant' => 'Teilnehmer',
    'label_audit_col_role' => 'Rolle',
    'label_audit_col_signed_at' => 'Unterschrieben am',
    'label_audit_col_status' => 'Status',
    'label_audit_role_participant' => 'Teilnehmer',
    'label_audit_role_gm' => 'GM',
];
