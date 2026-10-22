<?php

return [
    // Badge-Namen und -Beschreibungen (M069). Die Registry speichert die
    // vollständigen Translation-Keys; diese Copy ist die deutsche Fassung.
    // Terminologie gemäß Glossar: Runde (nie Sitzung), Host, Kampagne,
    // Anwesenheit — Du-Form überall.
    'content_badge_debut_name' => 'Debüt',
    'content_badge_debut_description' => 'Deine erste eigene Runde ausgerichtet — die Würfel sind gefallen.',
    'content_badge_first_seat_name' => 'Erste Teilnahme',
    'content_badge_first_seat_description' => 'Zum ersten Mal bei einer Runde dabei gewesen — vom Host bestätigt. Willkommen am Tisch.',
    'content_badge_founding_table_name' => 'Gründungstisch',
    'content_badge_founding_table_description' => 'Konto im ersten Jahr der Plattform angelegt — von Anfang an dabei.',
    'content_badge_chartered_name' => 'Charta besiegelt',
    'content_badge_chartered_description' => 'Eine Kampagne geleitet, deren Charta vom gesamten Tisch unterschrieben wurde.',
    'content_badge_campaign_compleat_name' => 'Kampagne vollendet',
    'content_badge_campaign_compleat_description' => 'Eine Kampagne mit mindestens sechs Spielrunden abgeschlossen — mit Journal-Einträgen für mindestens 80 % davon.',
    // Money-Badge-Ehrlichkeit (Spec §1.5): die Copy bescheinigt
    // finanzielle Unterstützung, nie Können.
    'content_badge_supporting_member_name' => 'Fördermitglied',
    'content_badge_supporting_member_description' => 'Unterstützt die Plattform mit einer aktiven Mitgliedschaft — danke, dass Du mithilfst, die Community am Laufen zu halten.',
    'content_badge_regulars_table_name' => 'Stammtisch',
    'content_badge_regulars_table_description' => 'Monat für Monat an bestätigten Runden dabei gewesen — pro sechs Monate ein Aussetzer verziehen. Drei, sechs oder zwölf Monate halten den Platz warm.',
    'content_badge_still_rolling_name' => 'Immer noch am Rollen',
    'content_badge_still_rolling_description' => 'Deine erste eigene Runde liegt ein, drei oder fünf Jahre zurück — und die Würfel rollen immer noch.',
    'content_badge_guild_linked_name' => 'Gilde verknüpft',
    'content_badge_guild_linked_description' => 'Dein Discord-Server ist seit über dreißig Tagen mit Roundup verknüpft — der Kalender der Gilde füllt sich weiter mit Runden.',
    'content_badge_trusted_organizer_name' => 'Vertrauenswürdiger Organisator',
    'content_badge_trusted_organizer_description' => 'Du organisierst Quartal für Quartal verlässlich: drei oder mehr abgeschlossene Events, von den Teilnehmenden mit 4,5 oder besser bewertet, jede Absage angekündigt — ein, drei oder sechs Quartale in Folge.',
    'content_badge_mentor_name' => 'Lehrmeister',
    'content_badge_mentor_description' => 'Fünf, fünfzehn oder vierzig Spielende haben ihre erste Runde überhaupt mit Dir als Game Master mit vollen fünf Sternen bewertet.',
    // Stufen-Labels für die Auszeichnungs-Kopie (M069 S02 T5).
    'content_badge_tier_bronze' => 'Bronze',
    'content_badge_tier_silver' => 'Silber',
    'content_badge_tier_gold' => 'Gold',
    'content_badge_tier_year_1' => 'Jahr 1',
    'content_badge_tier_year_2' => 'Jahr 2',
    'content_badge_tier_year_3' => 'Jahr 3',
    'content_badge_tier_year_5' => 'Jahr 5',
    // Seltenheits-Stufen (M069 S04, Spec §2.4): Anzeige-Gruppierung aus dem
    // nächtlichen Halter-Anteil — nie eine Aussage über Können.
    // content_badge_-Präfix gemäß i18n-Key-Konvention (wie content_badge_tier_*).
    'content_badge_rarity_common' => 'Gewöhnlich',
    'content_badge_rarity_uncommon' => 'Ungewöhnlich',
    'content_badge_rarity_rare' => 'Selten',
    'content_badge_rarity_elite' => 'Elitär',
    'content_badge_rarity_legendary' => 'Legendär',
    // Chip-Status-Beschriftungen (M069 S04): sichtbare Mikro-Copy in der
    // gemeinsamen x-badge-chip-Pille — ausgegraute Abgelaufen-/Ruhend-Zustände
    // und der Hervorgehoben-Stern. Es gibt keine Tooltip-Komponente,
    // daher wird alles als Text gerendert. Du-Form gemäß Glossar.
    'content_badge_state_re_earn' => 'Erneut verdienen',
    'content_badge_state_coming_soon' => 'Kommt bald',
    'content_badge_label_featured' => 'Hervorgehoben',
    // Profil-Regal (M069 S04 T3, Spec §2.4): Abschnittstitel, Bedienelemente
    // für Eigentümer (zugängliche Namen des Stern-Buttons) und die deutliche
    // Maximal-3-Ablehnung. Du-Form gemäß Glossar; „Abzeichen“ wie in den
    // Benachrichtigungen (subject_badge_awarded et al.).
    'content_badge_shelf_title' => 'Abzeichen',
    'content_badge_shelf_feature' => 'Dieses Abzeichen hervorheben',
    'content_badge_shelf_unfeature' => 'Hervorhebung dieses Abzeichens aufheben',
    'content_badge_shelf_limit_reached' => 'Du kannst maximal drei Abzeichen hervorheben — hebe zuerst eines auf, um zu tauschen.',
    // Galerie (M069 S04 T5, Spec §2.4): Kategorie-Labels lösen sich über
    // BadgeCategory::label() auf; Galerie-Seitencopy + SEO. Die öffentliche
    // /badges-Galerie ist die Entdeckungs- und SEO-Fläche für jede aktive
    // Definition. Du-Form gemäß Glossar; GM/Game Master bleibt erhalten.
    'category_support_tenure' => 'Unterstützer-Zeitraum',
    'category_community_infrastructure' => 'Community-Infrastruktur',
    'category_gm_craft' => 'GM-Handwerk',
    'category_participation' => 'Teilnahme',
    'category_events_organizing' => 'Events & Organisieren',
    'category_milestones' => 'Meilensteine',
    'title_badge_gallery' => 'Abzeichen',
    'seo_description_badge_gallery' => 'Alle Roundup-Abzeichen an einem Ort — was sie ehren, wie selten sie sind und wer sie trägt.',
    'content_badge_gallery_intro' => 'Alle Abzeichen auf Roundup — was sie ehren und wie selten sie unter den Spielenden sind, die sie verdienen könnten.',
    'filter_all_categories' => 'Alle',
    'filter_category_label' => 'Kategorie',
    'content_badge_gallery_empty' => 'In dieser Kategorie gibt es noch keine Abzeichen — schau bald wieder vorbei.',
    // Ehrlichkeitsregel der Boundary Map (D195): die reine Halter-Zahl
    // erscheint IMMER; der Anteil der Berechtigten nur oberhalb von
    // BadgeRarity::ELIGIBLE_THRESHOLD.
    'content_badge_holders' => 'Einmal vergeben|:count-mal vergeben',
    'content_badge_share_of_eligible' => 'Gehalten von :percent % der berechtigten Spielenden',
    'content_badge_share_of_eligible_gms' => 'Gehalten von :percent % der berechtigten Game Master',
];
