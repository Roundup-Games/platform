<?php

return [

    'pages' => [
        'email_settings' => [
            'title' => 'Email Settings',
            'email_addresses' => 'Email Addresses',
            'email_addresses_label' => 'Inbound email addresses for ticket creation',
            'default_reply_address' => 'Default Reply Address',
            'default_reply_address_label' => 'Default From address for outgoing ticket emails',
            'display_name' => 'Display Name',
            'email' => 'Email Address',
            'department' => 'Department',
            'dkim_status' => 'DKIM Status',
            'save_button' => 'Save Email Settings',
            'save_success' => 'Email settings saved successfully',
        ],

        'sso_settings' => [
            'title' => 'SSO Settings',
            'provider' => 'SSO Provider',
            'provider_label' => 'Select SSO Provider',
            'provider_none' => 'Disabled',
            'provider_saml' => 'SAML 2.0',
            'provider_jwt' => 'JWT',
            'saml_configuration' => 'SAML Configuration',
            'entity_id' => 'Entity ID',
            'sso_url' => 'SSO URL',
            'certificate' => 'X.509 Certificate',
            'jwt_configuration' => 'JWT Configuration',
            'jwt_secret' => 'JWT Secret',
            'jwt_algorithm' => 'JWT Algorithm',
            'attribute_mapping' => 'Attribute Mapping',
            'attr_email' => 'Email Attribute',
            'attr_name' => 'Name Attribute',
            'attr_role' => 'Role Attribute',
            'save_button' => 'Save SSO Settings',
            'save_success' => 'SSO settings saved successfully',
        ],
    ],

];
