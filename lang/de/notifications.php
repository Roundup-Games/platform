<?php

return [
    // Category labels
    'category_new_follower' => 'Neuer Follower',
    'category_game_invitation' => 'Spieleinladung',
    'category_campaign_invitation' => 'Kampagneneinladung',
    'category_team_invitation' => 'Teameinladung',
    'category_session_added_to_campaign' => 'Sitzung zur Kampagne hinzugefügt',
    'category_new_application' => 'Neue Bewerbung',
    'category_application_approved' => 'Bewerbung angenommen',
    'category_application_rejected' => 'Bewerbung abgelehnt',
    'category_participant_joined' => 'Teilnehmer beigetreten',
    'category_participant_removed' => 'Teilnehmer entfernt',
    'category_team_member_removed' => 'Teammitglied entfernt',
    'category_game_cancelled' => 'Spiel abgesagt',
    'category_game_completed' => 'Spiel abgeschlossen',
    'category_campaign_cancelled' => 'Kampagne abgesagt',
    'category_campaign_completed' => 'Kampagne abgeschlossen',
    'category_review_reported' => 'Bewertung gemeldet',

    // UI — Benachrichtigungsglocke
    'bell_label' => 'Benachrichtigungen (:count ungelesen)',
    'nav_label' => 'Benachrichtigungen',
    'dropdown_heading' => 'Benachrichtigungen',
    'action_mark_all_read' => 'Alle gelesen',
    'action_mark_read' => 'Als gelesen markieren',
    'action_view_all' => 'Alle Benachrichtigungen anzeigen',
    'label_notifications' => 'Benachrichtigungen',
    'empty_state' => 'Noch keine Benachrichtigungen',
    'page_title' => 'Benachrichtigungen',

    // Group labels
    'group_social' => 'Soziales',
    'group_invitations' => 'Einladungen',

    'group_moderation' => 'Moderation',
    'group_applications' => 'Bewerbungen',
    'group_participation' => 'Teilnahme',
    'group_status' => 'Status',
    'group_content' => 'Inhalte',

    // Benachrichtigungseinstellungen
    'content_notification_preferences' => 'Benachrichtigungseinstellungen',
    'flash_notification_preferences_saved' => 'Benachrichtigungseinstellungen gespeichert.',
    'channel_in_app' => 'In-App',
    'channel_email' => 'E-Mail',

    // Trinary state labels
    'state_off' => 'Aus',
    'state_in_app' => 'In-App',
    'state_all' => 'Alle',
    'hint_preference_states' => 'Wähle, wie du für jede Kategorie benachrichtigt werden möchtest. „In-App" zeigt Benachrichtigungen im Glocken-Symbol an. „Alle" sendet zusätzlich eine E-Mail.',
    'hint_preference_channels' => 'Schalte jeden Kanal pro Benachrichtigungskategorie ein oder aus. Push-Benachrichtigungen müssen unten im Gerätebereich aktiviert werden.',
    'channel_push' => 'Push',

    // Push-Geräteverwaltung
    'push_devices_heading' => 'Push-Geräte',
    'push_not_supported' => 'Push-Benachrichtigungen werden in diesem Browser nicht unterstützt.',
    'push_enable_description' => 'Aktiviere Push-Benachrichtigungen, um auf diesem Gerät Benachrichtigungen zu erhalten.',
    'push_enable_button' => 'Push aktivieren',
    'push_disable_button' => 'Deaktivieren',

    // Gemeinsame E-Mail-Texte
    'email_brand_name' => 'Roundup Games',
    'email_default_subject' => 'Benachrichtigung von Roundup Games',
    'email_unsubscribe' => 'Abmelden',
    'email_manage_settings' => 'Benachrichtigungseinstellungen',
    'email_footer_reason' => 'Du erhältst diese E-Mail, da du Benachrichtigungen für diese Art von Aktivität aktiviert hast.',
    'email_greeting' => 'Hallo :name,',
    'email_greeting_plain' => 'Hallo,',
    'email_view_details' => 'Details anzeigen',
    'email_view_entity' => ':entity anzeigen',
    'email_manage_participants' => 'Teilnehmer verwalten',

    // Entitätstyp-Bezeichnungen (Kleinschreibung, für Verwendung in Sätzen)
    // Abmeldung
    'unsubscribe_success' => 'Du wurdest von :category-E-Mail-Benachrichtigungen abgemeldet.',
    'unsubscribe_invalid_link' => 'Dieser Abmeldelink ist ungültig oder abgelaufen.',
    'unsubscribe_unknown_category' => 'Die Benachrichtigungskategorie konnte nicht gefunden werden.',

    // Entitätstyp-Bezeichnungen (Kleinschreibung, für Verwendung in Sätzen)
    'entity_type_game' => 'Spiel',
    'entity_type_campaign' => 'Kampagne',
    'entity_type_team' => 'Team',

    // Anzeigetexte für Benachrichtigungsliste (Verb + Satzbausteine)
    'verb_new_follower' => 'folgt dir',
    'verb_game_invitation' => 'hat dich zu einem Spiel eingeladen',
    'verb_campaign_invitation' => 'hat dich zu einer Kampagne eingeladen',
    'verb_team_invitation' => 'hat dich in ein Team eingeladen',
    'verb_session_added_to_campaign' => 'hat eine Sitzung zur Kampagne hinzugefügt',
    'verb_new_application' => 'hat sich beworben',
    'verb_application_approved' => 'hat deine Bewerbung angenommen',
    'verb_application_rejected' => 'hat deine Bewerbung abgelehnt',
    'verb_participant_joined' => 'ist beigetreten',
    'verb_participant_removed' => 'hat dich entfernt aus',
    'verb_team_member_removed' => 'hat dich aus dem Team entfernt',
    'verb_game_cancelled' => 'Spiel abgesagt',
    'verb_game_completed' => 'Spiel abgeschlossen',
    'verb_campaign_cancelled' => 'Kampagne abgesagt',
    'verb_campaign_completed' => 'Kampagne abgeschlossen',

    // Anzeigetexte — Satzbausteine (1/2/3+ Akteure)
    'display_one_actor' => ':actor :verb',
    'display_two_actors' => ':actor1 und :actor2 :verb',
    'display_many_actors' => ':actor1, :actor2 und :count weitere :verb|:actor1, :actor2 und :count weitere :verb',
    'display_no_actor_with_entity' => ':verb: :entity',
    'display_no_actor' => ':verb',

    // E-Mail-Betreffs — Soziales
    'subject_new_follower' => ':follower folgt dir jetzt',
    'body_new_follower' => '**:follower** folgt dir jetzt auf Roundup Games. Schau dir ihr Profil an und sag Hallo!',
    'action_new_follower' => 'Profil anzeigen',

    // E-Mail-Betreffs — Einladungen
    'subject_game_invitation' => ':inviter hat dich zu einem Spiel eingeladen',
    'body_game_invitation' => '**:inviter** hat dich eingeladen, am Spiel **:game** teilzunehmen. Schau dir die Details an und sag Bescheid.',
    'action_game_invitation' => 'Spiel anzeigen',

    'subject_campaign_invitation' => ':inviter hat dich zu einer Kampagne eingeladen',
    'body_campaign_invitation' => '**:inviter** hat dich eingeladen, der Kampagne **:campaign** beizutreten. Ein ganzes Abenteuer erwartet dich.',
    'action_campaign_invitation' => 'Kampagne anzeigen',

    'subject_team_invitation' => ':inviter hat dich eingeladen, :team beizutreten',
    'body_team_invitation' => '**:inviter** hat dich eingeladen, dem Team **:team** beizutreten. Spielt zusammen.',
    'action_team_invitation' => 'Team anzeigen',

    'subject_session_added_to_campaign' => 'Neue Sitzung zu :campaign hinzugefügt',
    'body_session_added_to_campaign' => 'Eine neue Sitzung wurde zur Kampagne **:campaign** hinzugefügt. Prüfe die Details und notiere dir den Termin.',
    'action_session_added_to_campaign' => 'Sitzung anzeigen',

    // E-Mail-Betreffs — Bewerbungen
    'subject_new_application' => ':applicant hat sich bei deinem :entity beworben',
    'body_new_application' => '**:applicant** hat sich bei deinem :entity_type **:entity** beworben. Prüfe das Profil und antworte.',
    'action_new_application' => 'Bewerbung prüfen',

    'subject_application_approved' => 'Deine Bewerbung für :entity wurde angenommen',
    'body_application_approved' => 'Tolle Neuigkeiten! Deine Bewerbung für **:entity** wurde angenommen. Du bist dabei!',
    'action_application_approved' => ':entity_type anzeigen',

    'subject_application_rejected' => 'Deine Bewerbung für :entity wurde nicht angenommen',
    'body_application_rejected' => 'Deine Bewerbung für **:entity** wurde dieses Mal nicht angenommen. Keine Sorge — es gibt noch viele weitere Spiele.',
    'action_application_rejected' => 'Spiele entdecken',

    // E-Mail-Betreffs — Teilnahme
    'subject_participant_joined' => ':participant hat sich deinem :entity angeschlossen',
    'body_participant_joined' => '**:participant** hat sich deinem :entity_type **:entity** angeschlossen. Je mehr, desto besser!',
    'action_participant_joined' => ':entity_type anzeigen',

    'subject_participant_removed' => 'Du wurdest aus :entity entfernt',
    'body_participant_removed' => 'Du wurdest aus **:entity** entfernt. Wenn du Fragen hast, wende dich an den Organisator.',
    'action_participant_removed' => 'Spiele entdecken',

    'subject_team_member_removed' => 'Du wurdest aus :team entfernt',
    'body_team_member_removed' => 'Du wurdest aus dem Team **:team** entfernt. Wenn du Fragen hast, kontaktiere den Team-Organisator.',
    'action_team_member_removed' => 'Teams entdecken',

    // E-Mail-Betreffs — Status
    'subject_game_cancelled' => ':game wurde abgesagt',
    'body_game_cancelled' => 'Das Spiel **:game** für :date wurde abgesagt. Schau dir andere verfügbare Spiele an.',
    'action_game_cancelled' => 'Spiele entdecken',

    'subject_game_completed' => ':game wurde abgeschlossen',
    'body_game_completed' => 'Das Spiel **:game** ist nun abgeschlossen. Danke fürs Mitspielen!',
    'action_game_completed' => 'Spiel anzeigen',

    'subject_campaign_cancelled' => ':campaign wurde abgesagt',
    'body_campaign_cancelled' => 'Die Kampagne **:campaign** wurde abgesagt. Durchsuche andere Kampagnen für dein nächstes Abenteuer.',
    'action_campaign_cancelled' => 'Kampagnen entdecken',

    'subject_campaign_completed' => ':campaign wurde abgeschlossen',
    'body_campaign_completed' => 'Die Kampagne **:campaign** ist nun abgeschlossen. Was für eine Reise! Entdecke dein nächstes Abenteuer.',
    'action_campaign_completed' => 'Kampagne anzeigen',

    'subject_review_reported' => 'Eine Bewertung wurde gemeldet',
    'body_review_reported' => '**:reporter** hat eine Bewertung gemeldet: **:reason**.',

    'body_review_content' => 'Bewertungsinhalt: :body',
    'body_review_rating' => 'Bewertung: :rating/5',

    // Spiel aktualisiert
    'category_game_updated' => 'Spiel aktualisiert',
    'subject_game_updated' => ':game wurde aktualisiert',
    'body_game_updated' => 'Das Spiel **:game** wurde aktualisiert. Änderungen: :fields.',
    'action_view_game' => 'Spiel anzeigen',

    // Spielsystem-Anfrage
    'category_game_system_request' => 'Spielsystem-Anfrage',
    'subject_game_system_request_approved' => 'Spielsystem hinzugefügt: :name',
    'body_game_system_request_approved' => 'Deine Anfrage für das Spielsystem **:name** wurde angenommen! Es ist jetzt auf der Plattform verfügbar.',
    'action_create_game' => 'Spiel erstellen',
    'subject_game_system_request_rejected' => 'Aktualisierung zur Spielsystem-Anfrage',
    'body_game_system_request_rejected' => 'Deine Anfrage für das Spielsystem **:name** konnte derzeit nicht hinzugefügt werden.',
    'body_rejection_reason' => 'Grund: :reason',
    'subject_game_system_request_duplicate' => 'Spielsystem existiert bereits',
    'body_game_system_request_duplicate' => 'Das angefragte Spielsystem **:name** existiert bereits als **:existing**.',
    'action_view_game_system' => 'Spielsystem ansehen',

    // Kampagne aktualisiert
    'category_campaign_updated' => 'Kampagne aktualisiert',
    'subject_campaign_updated' => ':campaign wurde aktualisiert',
    'body_campaign_updated' => 'Die Kampagne **:campaign** wurde aktualisiert. Änderungen: :fields.',
    'action_view_campaign' => 'Kampagne anzeigen',

    // Push-Benachrichtigungen Titel & Text
    'push_title_game_invitation' => 'Spieleinladung',
    'push_body_game_invitation' => ':inviter hat dich zu :game eingeladen',
    'push_title_campaign_invitation' => 'Kampagneneinladung',
    'push_body_campaign_invitation' => ':inviter hat dich zu :campaign eingeladen',
    'push_title_new_follower' => 'Neuer Follower',
    'push_body_new_follower' => ':follower folgt dir jetzt',
    'push_title_game_cancelled' => 'Spiel abgesagt',
    'push_body_game_cancelled' => ':game wurde abgesagt',
    'push_title_campaign_cancelled' => 'Kampagne abgesagt',
    'push_body_campaign_cancelled' => ':campaign wurde abgesagt',
    'push_title_session_reminder' => 'Spielerinnerung',
    'push_body_session_reminder' => ':game beginnt heute um :time',

    // Spieler auf die Bank gesetzt
    'subject_player_benched' => 'Du wurdest für :entity auf die Bank gesetzt',
    'body_player_benched' => 'Der Kader für **:entity** ist derzeit voll, daher wurdest du auf die Bank gesetzt. Wenn ein Platz frei wird, kann dich der Host befördern.',
    'action_player_benched' => ':entity_type anzeigen',
    'push_title_player_benched' => 'Du sitzt auf der Bank',
    'push_body_player_benched' => 'Du wurdest für :entity auf die Bank gesetzt',
];
