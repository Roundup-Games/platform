<?php

return [
    'heading_title' => 'Datenschutzerklärung',
    'seo_description' => 'Erfahre, wie '.config('company.display_name').' deine personenbezogenen Daten erfasst, verwendet und schützt. Unsere Datenschutzpraktiken verständlich erklärt.',

    // ── Einleitung ─────────────────────────────────────
    'heading_introduction' => 'Einleitung',
    'content_introduction_1' => config('company.legal_name').' („wir", „uns", „unser") betreibt die Plattform roundup.games — einen gemeinnützigen, quelloffenen Service, der Menschen hilft, lokale Brettspiel- und Tabletop-Rollenspiel-Sessions zu finden und zu organisieren.',
    'content_introduction_2' => 'Diese Datenschutzerklärung erläutert, welche personenbezogenen Daten wir erheben, warum wir sie erheben, auf welcher Rechtsgrundlage dies beruht, wer Zugriff hat und wie lange wir sie aufbewahren. Wir haben dies in klarer Sprache verfasst, weil Transparenz für uns keine Optionalität ist — sie ist grundlegend.',
    'content_introduction_3' => 'Diese Richtlinie gilt für alle Nutzer unserer Plattform, einschließlich Besucher, die ohne Konto surfen.',

    // ── Erhobene Daten ─────────────────────────────────
    'heading_data_we_collect' => 'Erhobene Daten',
    'content_data_intro' => 'Wir erheben nur Daten, die einem klaren, konkreten Zweck dienen. Hier ist, was wir erfassen und warum:',

    'heading_data_account' => 'Konto- und Profildaten',
    'content_data_account_purpose' => 'Um dein Konto zu erstellen und zu verwalten.',
    'content_data_account_items' => 'Name, E-Mail-Adresse, Anzeigename, Profilfoto (optional), Bio (optional), Geschlecht (optional — besondere Datenkategorie gemäß DSGVO Art. 9, nur mit deiner ausdrücklichen Einwilligung erhoben), Spracheinstellung.',

    'heading_data_location' => 'Standortdaten',
    'content_data_location_purpose' => 'Um Sessions, Spieler und Veranstaltungsorte in deiner Nähe vorzuschlagen.',
    'content_data_location_items' => 'Ungefährer Standort (Stadt-/Stadtteilebene). Du kannst diesen manuell festlegen. Wir speichern einen Geohash — nicht deine genaue Adresse — es sei denn, du gibst ausdrücklich eine Adresse für eine Veranstaltung an, die du organisierst.',

    'heading_data_gaming' => 'Spielepräferenzen',
    'content_data_gaming_purpose' => 'Um Session-Empfehlungen und Entdeckungsergebnisse zu personalisieren.',
    'content_data_gaming_items' => 'Lieblingsspielsysteme, Stimmungspräferenzen (kompetitiv, kooperativ usw.), gemiedene Spiele und Teams, denen du angehörst.',

    'heading_data_activity' => 'Aktivitäts- und Teilnahmedaten',
    'content_data_activity_purpose' => 'Um Anwesenheit zu verfolgen, Zuverlässigkeitswerte zu berechnen und die gemeinschaftliche Verantwortung zu unterstützen.',
    'content_data_activity_items' => 'Session-Anmeldungen, Anwesenheitsaufzeichnungen, verspätete Stornierungen, Nichterscheinen, eingereichte Bewertungen und Organisator-Historie.',

    'heading_data_communication' => 'Kommunikationsdaten',
    'content_data_communication_purpose' => 'Um Nachrichten zwischen Nutzern zu senden und gewünschte Benachrichtigungen zuzustellen.',
    'content_data_communication_items' => 'Direktnachrichten zwischen Nutzern, Benachrichtigungseinstellungen und E-Mail-Zustellungsprotokolle.',

    'heading_data_invitations' => 'Einladungsdaten',
    'content_data_invitations_purpose' => 'Um Organisatoren zu ermöglichen, Personen zu Spielen und Kampagnen einzuladen.',
    'content_data_invitations_items' => 'Wenn du jemanden per E-Mail einlädst, der kein Konto hat, speichern wir die E-Mail-Adresse im Teilnehmer-Datensatz. Eingeladene E-Mail-Adressen werden 90 Tage nach Ende des Spiels oder der Kampagne anonymisiert. Empfänger können sich jederzeit über einen Ein-Klick-Link in der Einladungs-E-Mail von zukünftigen Einladungen abmelden.',

    'heading_data_sensitive' => 'Besondere Datenkategorien (DSGVO Art. 9)',
    'content_data_sensitive_purpose' => 'Geschlecht ist die einzige besondere Datenkategorie, die wir erheben, und nur mit deiner ausdrücklichen Einwilligung.',
    'content_data_sensitive_items' => 'Geschlecht wird als besondere personenbezogene Datenkategorie gemäß DSGVO Art. 9 Abs. 2 Buchst. a mit deiner ausdrücklichen, informierten Einwilligung bei der Registrierung erhoben. Die Angabe des Geschlechts ist vollständig optional. Du kannst die Einwilligung jederzeit in deinen Profileinstellungen widerrufen, wodurch deine Geschlechtsdaten sofort aus unseren Datensätzen entfernt werden. Geschlecht wird niemals in API-Antworten offengelegt oder an Dritte weitergegeben.',

    'heading_data_technical' => 'Technische und Nutzungsdaten',
    'content_data_technical_purpose' => 'Um die Sicherheit zu gewährleisten, Probleme zu diagnostizieren und die Plattform zu verbessern.',
    'content_data_technical_items' => 'IP-Adresse, Browsertyp, Gerätetyp, besuchte Seiten, Sitzungsdauer und Fehlerprotokolle. Wenn du die Analyse-Einwilligung erteilst, kann unser Analyseanbieter zusätzlich Interaktionsereignisse (z. B. Klicks und Tippen) sowie eingegebene Formularfeldwerte erfassen, mit Ausnahme sensibler Felder (Passwörter, E-Mail, Zahlungsdaten), die stets maskiert werden.',

    'heading_data_payment' => 'Zahlungsdaten',
    'content_data_payment_purpose' => 'Um Abonnementzahlungen für Organisator-Tools zu verarbeiten.',
    'content_data_payment_items' => 'Wir speichern keine Kreditkartendaten. Die Zahlungsabwicklung erfolgt vollständig über Paddle.com, unseren Zahlungsanbieter. Wir erhalten lediglich eine Transaktionsreferenz und den Abonnementstatus.',

    // ── Rechtsgrundlagen (DSGVO) ───────────────────────
    'heading_legal_bases' => 'Rechtsgrundlagen der Verarbeitung (DSGVO)',
    'content_legal_intro' => 'Als in Deutschland ansässige Organisation verarbeiten wir deine Daten auf folgenden Rechtsgrundlagen:',
    'content_legal_contract' => 'Vertragserfüllung: Bereitstellung der Dienste, für die du dich angemeldet hast (Konto, Session-Verwaltung, Kommunikation).',
    'content_legal_consent' => 'Einwilligung: Analyse-Tracking (PostHog) und optionale cookiebasierte Funktionen. Du kannst deine Einwilligung jederzeit widerrufen.',
    'content_legal_legitimate' => 'Berechtigtes Interesse: Sicherheit, Fehlerüberwachung zur Stabilität des Dienstes, Betrugsprävention und Plattformverbesserung — stets abgewogen gegen deine Privatsphärerechte. Zu Stabilitätszwecken können unbehandelte Fehler auch ohne Analyse-Einwilligung aufgezeichnet werden; diese Aufzeichnungen enthalten ausschließlich den Anfragepfad (nicht die vollständige URL) und keine Formulardaten.',
    'content_legal_obligation' => 'Rechtliche Verpflichtung: Datenspeicherung nach deutschem Steuer- und Handelsrecht (z. B. Mitglieds- und Finanzunterlagen).',

    // ── Cookies & Tracking ─────────────────────────────
    'heading_cookies' => 'Cookies & Tracking',
    'content_cookies_intro' => 'Wir unterteilen Cookies und ähnliche Technologien in drei Kategorien. Die optionalen Kategorien kannst du jederzeit über die Cookie-Einstellungen steuern:',
    'content_cookies_necessary' => 'Notwendig: Sitzungsauthentifizierung, CSRF-Schutz und deine Spracheinstellung. Diese können nicht deaktiviert werden — ohne sie funktioniert die Seite nicht.',
    'content_cookies_analytics' => 'Analytik (PostHog, optional): Helfen uns zu verstehen, wie die Plattform genutzt wird, damit wir sie verbessern können. Wenn aktiviert, werden Seitenaufrufe, Klicks und andere Interaktionen sowie Formularfeldwerte erfasst (mit Ausnahme sensibler Felder, die stets maskiert werden). Die Analysedaten sind pseudonymisiert — Ereignisse werden mit einer opaken internen Kennung verknüpft, und dein Name und deine E-Mail-Adresse werden niemals an den Analyseanbieter weitergegeben.',
    'content_cookies_marketing' => 'Marketing (optional): Ermöglicht personalisierte Ansprache wie E-Mail-Kampagnen und Empfehlungs-/Teilungsverfolgung sowie die Messung der Kampagnenwirksamkeit. Dies ist die einzige Kategorie, unter der deine Kontaktdaten für die Ansprache an einen Anbieter weitergegeben werden können. Standardmäßig deaktiviert.',
    'content_cookies_control' => 'Du kannst deine Cookie-Einstellungen jederzeit über den Link „Cookie-Einstellungen“ in der Fußzeile verwalten. Der Widerruf der Einwilligung berührt nicht die Rechtmäßigkeit der bis zum Widerruf erfolgten Verarbeitung.',

    // ── Drittanbieter ──────────────────────────────────
    'heading_third_parties' => 'Drittanbieter-Services',
    'content_third_intro' => 'Wir teilen Daten nur mit Dienstleistern, die uns beim Betrieb der Plattform unterstützen. Jeder ist durch Datenverarbeitungsvereinbarungen gebunden:',

    'heading_third_posthog' => 'PostHog (Analysen)',
    'content_third_posthog_body' => 'Wir nutzen PostHog, gehostet auf der europäischen Cloud von PostHog (eu.i.posthog.com), als unseren Analyse-Auftragsverarbeiter auf Grundlage einer Datenverarbeitungsvereinbarung. PostHog-Daten werden innerhalb der EU verarbeitet und bis zu 13 Monate aufbewahrt. Die Analyse erfolgt strikt nur mit Einwilligung (opt-in): Ereignisse werden erst erfasst, nachdem du die Analyse-Einwilligung erteilt hast, und sie sind pseudonymisiert — verknüpft mit einer opaken internen Kennung, niemals mit deinem Namen oder deiner E-Mail-Adresse. Deine Kontaktdaten werden nur dann an einen Anbieter weitergegeben, wenn du zusätzlich die Marketing-Einwilligung erteilst (z. B. für E-Mail-Kampagnen).',

    'heading_third_paddle' => 'Paddle (Zahlungen)',
    'content_third_paddle_body' => 'Zahlungsabwicklung für Abonnements. Paddle verarbeitet alle Kreditkartendaten — wir sehen oder speichern sie nie. Paddle ist PCI-DSS-konform und verarbeitet Daten gemäß DSGVO. Siehe paddle.com/legal für deren Datenschutzerklärung.',

    'heading_third_cloudflare' => 'Cloudflare (Infrastruktur)',
    'content_third_cloudflare_body' => 'CDN, DDoS-Schutz und DNS. Cloudflare kann temporär IP-Adressen für Sicherheitszwecke protokollieren. Cloudflare ist DSGVO-konform. Siehe cloudflare.com/privacypolicy für Details.',

    'heading_third_nominatim' => 'Nominatim (Geocoding)',
    'content_third_nominatim_body' => 'Quelloffenes Geocoding von OpenStreetMap. Wird verwendet, um Adressen in Koordinaten umzuwandeln, wenn Organisatoren Veranstaltungsorte festlegen. Die Nutzung von Nominatim unterliegt der Datenschutzerklärung der OpenStreetMap Foundation.',

    // ── Deine Rechte (DSGVO) ───────────────────────────
    'heading_your_rights' => 'Deine Rechte',
    'content_rights_intro' => 'Unter der DSGVO hast du folgende Rechte bezüglich deiner personenbezogenen Daten:',
    'content_rights_access' => 'Auskunft: Fordere eine Kopie aller deiner personenbezogenen Daten an, die wir über dich speichern.',
    'content_rights_rectification' => 'Berichtigung: Korrigiere ungenaue oder unvollständige Daten.',
    'content_rights_erasure' => 'Löschung: Fordere die Löschung deiner Daten („Recht auf Vergessenwerden"), vorbehaltlich gesetzlicher Aufbewahrungspflichten.',
    'content_rights_portability' => 'Datenübertragbarkeit: Erhalte deine Daten in einem maschinenlesbaren Format.',
    'content_rights_objection' => 'Widerspruch: Widerspreche der Verarbeitung, die auf berechtigtem Interesse beruht.',
    'content_rights_restriction' => 'Einschränkung: Fordere, dass wir die Verarbeitung deiner Daten einschränken.',
    'content_rights_withdraw' => 'Einwilligung widerrufen: Für jede Verarbeitung, die auf Einwilligung beruht, kannst du diese jederzeit widerrufen, ohne die Rechtmäßigkeit der bisherigen Verarbeitung zu berühren.',
    'content_rights_exercise' => 'Um eines dieser Rechte auszuüben, kontaktiere uns unter',
    'content_rights_complaint' => 'Wenn du der Meinung bist, dass deine Datenrechte verletzt wurden, hast du das Recht, Beschwerde bei einer Aufsichtsbehörde einzulegen, wie z. B. dem Bayerischen Landesamt für Datenschutzaufsicht (BayLDA).',

    // ── Datenspeicherung ───────────────────────────────
    'heading_data_retention' => 'Datenspeicherung',
    'content_retention_intro' => 'Wir bewahren deine Daten nur so lange auf, wie es erforderlich ist:',
    'content_retention_account' => 'Kontodaten: Werden aufbewahrt, solange dein Konto aktiv ist. Wenn du dein Konto löschst, werden deine personenbezogenen Daten (Name, E-Mail, Profilfoto und sonstige PII) entfernt und durch anonymisierte Kennungen ersetzt. Deine Bewertungen, Spielpartizipationsverlauf und Kampagnenbeiträge bleiben erhalten, um die Datenintegrität für andere Nutzer zu wahren. Nicht-personenbezogene Betriebsdaten können bei gesetzlicher Verpflichtung länger aufbewahrt werden.',
    'content_retention_activity' => 'Aktivitätsdaten: Anwesenheitsaufzeichnungen und Zuverlässigkeitswerte werden für die Lebensdauer des Kontos aufbewahrt, um die Bewertungsgenauigkeit zu gewährleisten.',
    'content_retention_analytics' => 'Analysedaten: PostHog-Daten werden bis zu 13 Monate aufbewahrt und dann automatisch gelöscht. Marketing-/Ansprachedaten (z. B. das Engagement bei E-Mail-Kampagnen), sofern du dies zugelassen hast, werden gespeichert, solange die Marketing-Einwilligung aktiv ist, und bis zu 13 Monate nach deren Widerruf.',
    'content_retention_legal' => 'Gesetzliche Anforderungen: Finanzunterlagen und Vereinsmitgliedsdaten werden nach deutschem Recht aufbewahrt (in der Regel 6–10 Jahre).',

    // ── Kontakt ────────────────────────────────────────
    'heading_contact' => 'Kontakt',
    'content_contact_intro' => 'Bei Fragen zu dieser Datenschutzerklärung oder deinen personenbezogenen Daten kontaktiere uns:',
    'content_contact_org' => config('company.legal_name'),
    'content_contact_email' => 'E-Mail: '.config('company.contact.privacy'),

    // ── Letzte Aktualisierung ──────────────────────────
    'content_last_updated' => 'Zuletzt aktualisiert: :date',
];
