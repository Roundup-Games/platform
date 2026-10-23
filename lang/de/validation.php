<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Deutsche Übersetzungen der Framework-Validierungsmeldungen (Laravel 13).
    | Die uuid-Meldung ist bewusst übersteuert („gültige UUID“ ist ein
    | Entwicklerdetail und erreicht niemals die Nutzer:innen), und
    | validation.attributes ersetzt rohe Feldnamen durch die Labels, die auf
    | den großen Formularen sichtbar sind (Runde erstellen, Onboarding,
    | Event-Gebühren).
    |
    */

    'accepted' => 'Das Feld :attribute muss akzeptiert werden.',
    'accepted_if' => 'Das Feld :attribute muss akzeptiert werden, wenn :other :value ist.',
    'active_url' => 'Das Feld :attribute muss eine gültige URL sein.',
    'after' => 'Das Feld :attribute muss ein Datum nach :date sein.',
    'after_or_equal' => 'Das Feld :attribute muss ein Datum nach oder gleich :date sein.',
    'alpha' => 'Das Feld :attribute darf nur Buchstaben enthalten.',
    'alpha_dash' => 'Das Feld :attribute darf nur Buchstaben, Zahlen, Bindestriche und Unterstriche enthalten.',
    'alpha_num' => 'Das Feld :attribute darf nur Buchstaben und Zahlen enthalten.',
    'any_of' => 'Das Feld :attribute ist ungültig.',
    'array' => 'Das Feld :attribute muss eine Liste sein.',
    'array_keys' => 'Das Feld :attribute darf nur die folgenden Schlüssel enthalten: :values.',
    'ascii' => 'Das Feld :attribute darf nur einbyteige alphanumerische Zeichen und Symbole enthalten.',
    'base64' => 'Das Feld :attribute muss ein gültiger Base64-String sein.',
    'before' => 'Das Feld :attribute muss ein Datum vor :date sein.',
    'before_or_equal' => 'Das Feld :attribute muss ein Datum vor oder gleich :date sein.',
    'between' => [
        'array' => 'Das Feld :attribute muss zwischen :min und :max Einträge haben.',
        'file' => 'Das Feld :attribute muss zwischen :min und :max Kilobyte groß sein.',
        'numeric' => 'Das Feld :attribute muss zwischen :min und :max liegen.',
        'string' => 'Das Feld :attribute muss zwischen :min und :max Zeichen lang sein.',
    ],
    'boolean' => 'Das Feld :attribute muss wahr oder falsch sein.',
    'can' => 'Das Feld :attribute enthält einen nicht autorisierten Wert.',
    'confirmed' => 'Die Bestätigung des Feldes :attribute stimmt nicht überein.',
    'contains' => 'Im Feld :attribute fehlt ein erforderlicher Wert.',
    'current_password' => 'Das Passwort ist falsch.',
    'date' => 'Das Feld :attribute muss ein gültiges Datum sein.',
    'date_equals' => 'Das Feld :attribute muss ein Datum gleich :date sein.',
    'date_format' => 'Das Feld :attribute muss dem Format :format entsprechen.',
    'decimal' => 'Das Feld :attribute muss :decimal Dezimalstellen haben.',
    'declined' => 'Das Feld :attribute muss abgelehnt werden.',
    'declined_if' => 'Das Feld :attribute muss abgelehnt werden, wenn :other :value ist.',
    'different' => 'Die Felder :attribute und :other müssen unterschiedlich sein.',
    'digits' => 'Das Feld :attribute muss :digits Ziffern haben.',
    'digits_between' => 'Das Feld :attribute muss zwischen :min und :max Ziffern haben.',
    'dimensions' => 'Das Feld :attribute hat ungültige Bildmaße.',
    'distinct' => 'Das Feld :attribute enthält einen doppelten Wert.',
    'doesnt_contain' => 'Das Feld :attribute darf keines der folgenden enthalten: :values.',
    'doesnt_end_with' => 'Das Feld :attribute darf nicht mit einem der folgenden enden: :values.',
    'doesnt_start_with' => 'Das Feld :attribute darf nicht mit einem der folgenden beginnen: :values.',
    'email' => 'Das Feld :attribute muss eine gültige E-Mail-Adresse sein.',
    'encoding' => 'Das Feld :attribute muss in :encoding kodiert sein.',
    'ends_with' => 'Das Feld :attribute muss mit einem der folgenden enden: :values.',
    'enum' => 'Die Auswahl für :attribute ist ungültig.',
    'exists' => 'Die Auswahl für :attribute ist ungültig.',
    'extensions' => 'Das Feld :attribute muss eine der folgenden Dateiendungen haben: :values.',
    'file' => 'Das Feld :attribute muss eine Datei sein.',
    'filled' => 'Das Feld :attribute muss ausgefüllt sein.',
    'gt' => [
        'array' => 'Das Feld :attribute muss mehr als :value Einträge haben.',
        'file' => 'Das Feld :attribute muss größer als :value Kilobyte sein.',
        'numeric' => 'Das Feld :attribute muss größer als :value sein.',
        'string' => 'Das Feld :attribute muss länger als :value Zeichen sein.',
    ],
    'gte' => [
        'array' => 'Das Feld :attribute muss :value Einträge oder mehr haben.',
        'file' => 'Das Feld :attribute muss mindestens :value Kilobyte groß sein.',
        'numeric' => 'Das Feld :attribute muss größer oder gleich :value sein.',
        'string' => 'Das Feld :attribute muss mindestens :value Zeichen lang sein.',
    ],
    'hex_color' => 'Das Feld :attribute muss ein gültiger Hex-Farbwert sein.',
    'image' => 'Das Feld :attribute muss ein Bild sein.',
    'in' => 'Die Auswahl für :attribute ist ungültig.',
    'in_array' => 'Das Feld :attribute muss in :other vorhanden sein.',
    'in_array_keys' => 'Das Feld :attribute muss mindestens einen der folgenden Schlüssel enthalten: :values.',
    'integer' => 'Das Feld :attribute muss eine ganze Zahl sein.',
    'ip' => 'Das Feld :attribute muss eine gültige IP-Adresse sein.',
    'ipv4' => 'Das Feld :attribute muss eine gültige IPv4-Adresse sein.',
    'ipv6' => 'Das Feld :attribute muss eine gültige IPv6-Adresse sein.',
    'json' => 'Das Feld :attribute muss ein gültiger JSON-String sein.',
    'list' => 'Das Feld :attribute muss eine Liste sein.',
    'lowercase' => 'Das Feld :attribute muss in Kleinbuchstaben geschrieben sein.',
    'lt' => [
        'array' => 'Das Feld :attribute muss weniger als :value Einträge haben.',
        'file' => 'Das Feld :attribute muss kleiner als :value Kilobyte sein.',
        'numeric' => 'Das Feld :attribute muss kleiner als :value sein.',
        'string' => 'Das Feld :attribute muss kürzer als :value Zeichen sein.',
    ],
    'lte' => [
        'array' => 'Das Feld :attribute darf nicht mehr als :value Einträge haben.',
        'file' => 'Das Feld :attribute darf höchstens :value Kilobyte groß sein.',
        'numeric' => 'Das Feld :attribute muss kleiner oder gleich :value sein.',
        'string' => 'Das Feld :attribute darf höchstens :value Zeichen lang sein.',
    ],
    'mac_address' => 'Das Feld :attribute muss eine gültige MAC-Adresse sein.',
    'max' => [
        'array' => 'Das Feld :attribute darf nicht mehr als :max Einträge haben.',
        'file' => 'Das Feld :attribute darf nicht größer als :max Kilobyte sein.',
        'numeric' => 'Das Feld :attribute darf nicht größer als :max sein.',
        'string' => 'Das Feld :attribute darf nicht länger als :max Zeichen sein.',
    ],
    'max_digits' => 'Das Feld :attribute darf nicht mehr als :max Ziffern haben.',
    'mimes' => 'Das Feld :attribute muss eine Datei vom Typ :values sein.',
    'mimetypes' => 'Das Feld :attribute muss eine Datei vom Typ :values sein.',
    'min' => [
        'array' => 'Das Feld :attribute muss mindestens :min Einträge haben.',
        'file' => 'Das Feld :attribute muss mindestens :min Kilobyte groß sein.',
        'numeric' => 'Das Feld :attribute muss mindestens :min sein.',
        'string' => 'Das Feld :attribute muss mindestens :min Zeichen lang sein.',
    ],
    'min_digits' => 'Das Feld :attribute muss mindestens :min Ziffern haben.',
    'missing' => 'Das Feld :attribute muss fehlen.',
    'missing_if' => 'Das Feld :attribute muss fehlen, wenn :other :value ist.',
    'missing_unless' => 'Das Feld :attribute muss fehlen, außer :other ist :value.',
    'missing_with' => 'Das Feld :attribute muss fehlen, wenn :values angegeben ist.',
    'missing_with_all' => 'Das Feld :attribute muss fehlen, wenn :values angegeben sind.',
    'multiple_of' => 'Das Feld :attribute muss ein Vielfaches von :value sein.',
    'not_in' => 'Die Auswahl für :attribute ist ungültig.',
    'not_regex' => 'Das Format des Feldes :attribute ist ungültig.',
    'numeric' => 'Das Feld :attribute muss eine Zahl sein.',
    'password' => [
        'letters' => 'Das Feld :attribute muss mindestens einen Buchstaben enthalten.',
        'mixed' => 'Das Feld :attribute muss mindestens einen Groß- und einen Kleinbuchstaben enthalten.',
        'numbers' => 'Das Feld :attribute muss mindestens eine Zahl enthalten.',
        'symbols' => 'Das Feld :attribute muss mindestens ein Sonderzeichen enthalten.',
        'uncompromised' => 'Das angegebene :attribute ist in einem Datenleck aufgetaucht. Bitte wähle ein anderes :attribute.',
    ],
    'present' => 'Das Feld :attribute muss vorhanden sein.',
    'present_if' => 'Das Feld :attribute muss vorhanden sein, wenn :other :value ist.',
    'present_unless' => 'Das Feld :attribute muss vorhanden sein, außer :other ist :value.',
    'present_with' => 'Das Feld :attribute muss vorhanden sein, wenn :values angegeben ist.',
    'present_with_all' => 'Das Feld :attribute muss vorhanden sein, wenn :values angegeben sind.',
    'prohibited' => 'Das Feld :attribute ist unzulässig.',
    'prohibited_if' => 'Das Feld :attribute ist unzulässig, wenn :other :value ist.',
    'prohibited_if_accepted' => 'Das Feld :attribute ist unzulässig, wenn :other akzeptiert wurde.',
    'prohibited_if_declined' => 'Das Feld :attribute ist unzulässig, wenn :other abgelehnt wurde.',
    'prohibited_unless' => 'Das Feld :attribute ist unzulässig, außer :other ist in :values.',
    'prohibits' => 'Das Feld :attribute verbietet die Angabe von :other.',
    'regex' => 'Das Format des Feldes :attribute ist ungültig.',
    'required' => 'Das Feld :attribute ist erforderlich.',
    'required_array_keys' => 'Das Feld :attribute muss Einträge für Folgendes enthalten: :values.',
    'required_if' => 'Das Feld :attribute ist erforderlich, wenn :other :value ist.',
    'required_if_accepted' => 'Das Feld :attribute ist erforderlich, wenn :other akzeptiert wurde.',
    'required_if_declined' => 'Das Feld :attribute ist erforderlich, wenn :other abgelehnt wurde.',
    'required_unless' => 'Das Feld :attribute ist erforderlich, außer :other ist in :values.',
    'required_with' => 'Das Feld :attribute ist erforderlich, wenn :values angegeben ist.',
    'required_with_all' => 'Das Feld :attribute ist erforderlich, wenn :values angegeben sind.',
    'required_without' => 'Das Feld :attribute ist erforderlich, wenn :values nicht angegeben ist.',
    'required_without_all' => 'Das Feld :attribute ist erforderlich, wenn keines der Felder :values angegeben ist.',
    'same' => 'Die Felder :attribute und :other müssen übereinstimmen.',
    'size' => [
        'array' => 'Das Feld :attribute muss :size Einträge enthalten.',
        'file' => 'Das Feld :attribute muss :size Kilobyte groß sein.',
        'numeric' => 'Das Feld :attribute muss :size sein.',
        'string' => 'Das Feld :attribute muss :size Zeichen lang sein.',
    ],
    'starts_with' => 'Das Feld :attribute muss mit einem der folgenden beginnen: :values.',
    'string' => 'Das Feld :attribute muss ein Text sein.',
    'timezone' => 'Das Feld :attribute muss eine gültige Zeitzone sein.',
    'unique' => ':attribute ist bereits vergeben.',
    'uploaded' => 'Das Hochladen von :attribute ist fehlgeschlagen.',
    'uppercase' => 'Das Feld :attribute muss in Großbuchstaben geschrieben sein.',
    'url' => 'Das Feld :attribute muss eine gültige URL sein.',
    'ulid' => 'Das Feld :attribute muss eine gültige ULID sein.',
    'uuid' => 'Die Auswahl für :attribute ist ungültig.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Attributspezifische Regel-Overrides. Der Spielsystem-Picker übermittelt
    | ein Array von UUIDs; ein UUID-Fehler bedeutet dort „deine Auswahl ist
    | unbrauchbar“ und nicht „lerne, was eine UUID ist“.
    |
    */

    'custom' => [
        'game_systems.*' => [
            'uuid' => 'Wähle mindestens ein Spielsystem aus.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | Anzeigefreundliche Namen für die großen Formulare. Generisch halten,
    | wo ein Feldname von mehreren Formularen geteilt wird (name,
    | description), und spezifisch, wo er eindeutig ist (date_time,
    | game_systems, Gebührenfelder). Die Werte entsprechen den Labels, die
    | auf dem Formular sichtbar sind.
    |
    */

    'attributes' => [
        // Anmelde-Formulare
        'email' => 'E-Mail-Adresse',
        'password' => 'Passwort',
        'current_password' => 'Aktuelles Passwort',

        // Formular „Runde erstellen“
        'name' => 'Name',
        'description' => 'Beschreibung',
        'date_time' => 'Datum & Uhrzeit',
        'signup_cutoff_at' => 'Anmeldeschluss',
        'min_players' => 'Minimale Spielerzahl',
        'max_players' => 'Maximale Spielerzahl',
        'game_system_id' => 'Spielsystem',
        'game_systems' => 'Spielsysteme',
        'game_systems.*' => 'Spielsysteme',
        'expected_duration' => 'Erwartete Dauer',
        'price' => 'Preis',
        'language' => 'Sprache',

        // Onboarding (CompleteProfile)
        'city' => 'Stadt',
        'gender' => 'Geschlecht',
        'pronouns' => 'Pronomen',
        'phone' => 'Telefonnummer',
        'slug' => 'Benutzername',
        'favoriteGameSystemIds' => 'Lieblingsspielsysteme',
        'favoriteGameSystemIds.*' => 'Lieblingsspielsysteme',

        // Veranstalter-Formulare (Gebühren & Kontakt)
        'max_participants' => 'Maximale Teilnehmerzahl',
        'individual_registration_fee' => 'Einzel-Anmeldegebühr',
        'early_bird_discount' => 'Frühbucher-Rabatt',
        'early_bird_deadline' => 'Frühbucher-Deadline',
        'contact_email' => 'Kontakt-E-Mail',
        'contact_phone' => 'Kontakt-Telefonnummer',

        'title' => 'Charter-Titel',
        'amendmentChangelog' => __('charter.field_amendment_changelog_label'),


        // Charter composer (M070): validation messages must name the
        // field labels players see on screen, never the dot paths.
        // Values mirror the charter.* labels (kept literal: the i18n
        // checker cannot resolve __() calls inside lang files).
        'title' => 'Charter-Titel',

        'content.overview.pitch' => 'Pitch',
        'content.overview.system_notes' => 'System, Edition & erlaubte Quellen',
        'content.overview.tone_serious_lighthearted' => 'Ernst ↔ Unbeschwert',
        'content.overview.tone_gritty_heroic' => 'Grimmig ↔ Heroisch',
        'content.overview.tone_political_dungeon' => 'Politisch ↔ Dungeon-Crawl',
        'content.overview.themes' => 'Themen',
        'content.overview.themes.*' => 'Themen',
        'content.overview.expected_shape' => 'Voraussichtlicher Umfang',
        'content.overview.table_size' => 'Tischgröße',
        'content.overview.may_add_players' => 'Wir dürfen später Spieler aufnehmen',

        'content.boundaries.lines_categories' => 'Kategorien',
        'content.boundaries.lines_categories.*' => 'Kategorien',
        'content.boundaries.veils_categories' => 'Kategorien',
        'content.boundaries.veils_categories.*' => 'Kategorien',
        'content.boundaries.lines_text' => 'In unseren eigenen Worten',
        'content.boundaries.veils_text' => 'In unseren eigenen Worten',
        'content.boundaries.intro_note' => 'Einleitung für deine Spieler',

        'content.safety.tools' => 'Genutzte Tools',
        'content.safety.tools.*' => 'Genutzte Tools',
        'content.safety.custom_note' => 'Eigene Tool-Notiz',
        'content.safety.signal_how' => 'Wie man an diesem Tisch signalisiert',
        'content.safety.revisit' => 'Auffrisch-Rhythmus',
        'content.safety.open_door' => 'Open Door gilt',

        'content.policies.pvp' => 'Spieler gegen Spieler (PvP)',
        'content.policies.character_death' => 'Charaktertod',
        'content.policies.advancement' => 'Entwicklung',
        'content.policies.loot_policy' => 'Loot-&-Schatz-Regel',
        'content.policies.homebrew' => 'Homebrew & Third-Party-Inhalte',
        'content.policies.etiquette' => 'Tisch-Etikette',
        'content.policies.etiquette.*' => 'Tisch-Etikette',
        'content.policies.etiquette_text' => 'Weitere Etikette in unseren eigenen Worten',

        'content.ai_tools.ai_prep' => 'KI-generierte Inhalte in der Vorbereitung',
        'content.ai_tools.ai_transcription' => 'KI-Notizen & Transkription',
        'content.ai_tools.dice' => 'Würfel',
        'content.ai_tools.sheets' => 'Charakterblätter & Nachschlagewerke',
        'content.ai_tools.photography' => 'Fotos vom Tisch & Miniaturen',
        'content.ai_tools.recording' => 'Audio-/Videoaufnahmen',
        'content.ai_tools.custom_clause' => 'Eigene Klausel',

        'content.logistics.venue_mode' => 'Spielort',
        'content.logistics.location_id' => 'Treffpunkt',
        'content.logistics.venue_access_notes' => 'Barrierefreiheit & Hinweise zum Spielort',
        'content.logistics.food_drink' => 'Essen & Trinken',
        'content.logistics.travel_notes' => 'Anfahrt & Ankunft',
        'content.logistics.cadence' => 'Rundenrhythmus',
        'content.logistics.session_length' => 'Rundendauer',
        'content.logistics.session_length_other' => 'Andere Rundendauer',
        'content.logistics.attendance_note' => 'Anwesenheitserwartung',
        'content.logistics.max_missed_sessions' => 'Grenze für Fehlrunden',
        'content.logistics.absence_notice' => 'Absage-Frist',
        'content.logistics.missing_player' => 'Wenn ein Spieler fehlt',
        'content.logistics.comms' => 'Kommunikationskanal',
        'content.logistics.comms.*' => 'Kommunikationskanal',
        'content.logistics.comms_other' => 'Anderer Kanal',

        'content.character.build_constraints' => 'Bau-Regeln',
        'content.character.backstory_requested' => 'Hintergrund-Hooks erwünscht',
        'content.character.backstory_guidance' => 'Hinweise zur Hintergrundgeschichte',

        'amendmentChangelog' => 'Änderungsprotokoll',
    ],

];
