<?php

return [

    'pages' => [
        'email_settings' => [
            'title' => 'E-Mail-Einstellungen',
            'email_addresses' => 'E-Mail-Adressen',
            'email_addresses_label' => 'Eingehende E-Mail-Adressen für Ticketerstellung',
            'default_reply_address' => 'Standard-Antwortadresse',
            'default_reply_address_label' => 'Standard-Absenderadresse für ausgehende Ticket-E-Mails',
            'display_name' => 'Anzeigename',
            'email' => 'E-Mail-Adresse',
            'department' => 'Abteilung',
            'dkim_status' => 'DKIM-Status',
            'save_button' => 'E-Mail-Einstellungen speichern',
            'save_success' => 'E-Mail-Einstellungen erfolgreich gespeichert',
        ],

        'sso_settings' => [
            'title' => 'SSO-Einstellungen',
            'provider' => 'SSO-Anbieter',
            'provider_label' => 'SSO-Anbieter auswählen',
            'provider_none' => 'Deaktiviert',
            'provider_saml' => 'SAML 2.0',
            'provider_jwt' => 'JWT',
            'saml_configuration' => 'SAML-Konfiguration',
            'entity_id' => 'Entity-ID',
            'sso_url' => 'SSO-URL',
            'certificate' => 'X.509-Zertifikat',
            'jwt_configuration' => 'JWT-Konfiguration',
            'jwt_secret' => 'JWT-Secret',
            'jwt_algorithm' => 'JWT-Algorithmus',
            'attribute_mapping' => 'Attributzuordnung',
            'attr_email' => 'E-Mail-Attribut',
            'attr_name' => 'Namensattribut',
            'attr_role' => 'Rollenattribut',
            'save_button' => 'SSO-Einstellungen speichern',
            'save_success' => 'SSO-Einstellungen erfolgreich gespeichert',
        ],
    ],

];
