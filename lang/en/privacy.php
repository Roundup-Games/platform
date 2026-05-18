<?php

return [
    'heading_title' => 'Privacy Policy',
    'seo_description' => 'Learn how ' . config('company.display_name') . ' collects, uses, and protects your personal data. Our privacy practices explained in plain language.',

    // ── Introduction ───────────────────────────────────
    'heading_introduction' => 'Introduction',
    'content_introduction_1' => config('company.legal_name') . ' ("we", "us", "our") operates the roundup.games platform — a non-profit, open-source service that helps people find and organize local, in-person tabletop gaming sessions.',
    'content_introduction_2' => 'This Privacy Policy explains what personal data we collect, why we collect it, the legal basis for doing so, who has access to it, and how long we keep it. We wrote this in plain language because transparency isn\'t optional for us — it\'s foundational.',
    'content_introduction_3' => 'This policy applies to all users of our platform, including visitors who browse without creating an account.',

    // ── Data We Collect ────────────────────────────────
    'heading_data_we_collect' => 'Data We Collect',
    'content_data_intro' => 'We only collect data that serves a clear, specific purpose. Here\'s what we gather and why:',

    'heading_data_account' => 'Account & Profile Data',
    'content_data_account_purpose' => 'To create and maintain your account.',
    'content_data_account_items' => 'Name, email address, display name, profile photo (optional), bio (optional), gender (optional), language preference.',

    'heading_data_location' => 'Location Data',
    'content_data_location_purpose' => 'To suggest nearby sessions, players, and venues.',
    'content_data_location_items' => 'Approximate location (city/neighborhood level). You can set this manually. We store a geohash — not your exact address — unless you explicitly provide an address for an event you organize.',

    'heading_data_gaming' => 'Gaming Preferences',
    'content_data_gaming_purpose' => 'To personalize session recommendations and discovery results.',
    'content_data_gaming_items' => 'Favorite game systems, vibe preferences (competitive, cooperative, etc.), avoided games, and teams you belong to.',

    'heading_data_activity' => 'Activity & Participation Data',
    'content_data_activity_purpose' => 'To track attendance, compute reliability scores, and support community accountability.',
    'content_data_activity_items' => 'Session sign-ups, attendance records, late cancellations, no-shows, reviews submitted, and organizer history.',

    'heading_data_communication' => 'Communication Data',
    'content_data_communication_purpose' => 'To send messages between users and deliver notifications you\'ve requested.',
    'content_data_communication_items' => 'Direct messages between users, notification preferences, and email delivery records.',

    'heading_data_technical' => 'Technical & Usage Data',
    'content_data_technical_purpose' => 'To maintain security, diagnose issues, and improve the platform.',
    'content_data_technical_items' => 'IP address, browser type, device type, pages visited, session duration, and error logs.',

    'heading_data_payment' => 'Payment Data',
    'content_data_payment_purpose' => 'To process subscription payments for organizer tools.',
    'content_data_payment_items' => 'We do not store credit card details. Payment processing is handled entirely by Paddle.com, our payment provider. We receive only a transaction reference and subscription status.',

    // ── Legal Bases (GDPR) ─────────────────────────────
    'heading_legal_bases' => 'Legal Bases for Processing (GDPR)',
    'content_legal_intro' => 'As an organization based in Germany, we process your data under the following legal bases:',
    'content_legal_contract' => 'Contract performance: Providing the services you signed up for (account, session management, communication).',
    'content_legal_consent' => 'Consent: Analytics tracking (PostHog) and optional cookie-based features. You can withdraw consent at any time.',
    'content_legal_legitimate' => 'Legitimate interest: Security, fraud prevention, and platform improvement — always balanced against your privacy rights.',
    'content_legal_obligation' => 'Legal obligation: Data retention required by German tax and commercial law (e.g., membership and financial records).',

    // ── Cookies & Tracking ─────────────────────────────
    'heading_cookies' => 'Cookies & Tracking',
    'content_cookies_intro' => 'We use a minimal set of cookies:',
    'content_cookies_necessary' => 'Necessary cookies: Session authentication, CSRF protection, language preference. These cannot be disabled.',
    'content_cookies_analytics' => 'Analytics cookies (PostHog): Help us understand how the platform is used so we can improve it. These are optional and only activated with your consent.',
    'content_cookies_control' => 'You can manage your cookie preferences at any time using the Cookie Settings link in the footer.',

    // ── Third Parties ──────────────────────────────────
    'heading_third_parties' => 'Third-Party Services',
    'content_third_intro' => 'We share data only with service providers who help us operate the platform. Each is bound by data processing agreements:',

    'heading_third_posthog' => 'PostHog (Analytics)',
    'content_third_posthog_body' => 'Self-hosted analytics. We use PostHog to understand feature usage and user flows. Data is pseudonymized where possible. PostHog data is processed within the EU.',

    'heading_third_paddle' => 'Paddle (Payments)',
    'content_third_paddle_body' => 'Payment processing for subscriptions. Paddle handles all credit card data — we never see or store it. Paddle is PCI-DSS compliant and processes data in accordance with GDPR. See paddle.com/legal for their privacy policy.',

    'heading_third_cloudflare' => 'Cloudflare (Infrastructure)',
    'content_third_cloudflare_body' => 'CDN, DDoS protection, and DNS. Cloudflare may temporarily log IP addresses for security purposes. Cloudflare is committed to GDPR compliance. See cloudflare.com/privacypolicy for details.',

    'heading_third_nominatim' => 'Nominatim (Geocoding)',
    'content_third_nominatim_body' => 'Open-source geocoding by OpenStreetMap. Used to convert addresses to coordinates when organizers set event locations. Nominatim usage is governed by the OpenStreetMap Foundation\'s privacy policy.',

    // ── Your Rights (GDPR) ─────────────────────────────
    'heading_your_rights' => 'Your Rights',
    'content_rights_intro' => 'Under the GDPR, you have the following rights regarding your personal data:',
    'content_rights_access' => 'Access: Request a copy of all personal data we hold about you.',
    'content_rights_rectification' => 'Rectification: Correct inaccurate or incomplete data.',
    'content_rights_erasure' => 'Erasure: Request deletion of your data ("right to be forgotten"), subject to legal retention obligations.',
    'content_rights_portability' => 'Portability: Receive your data in a machine-readable format.',
    'content_rights_objection' => 'Objection: Object to processing based on legitimate interest.',
    'content_rights_restriction' => 'Restriction: Request that we limit how we process your data.',
    'content_rights_withdraw' => 'Withdraw consent: For any processing based on consent, you can withdraw at any time without affecting the lawfulness of prior processing.',
    'content_rights_exercise' => 'To exercise any of these rights, contact us at',
    'content_rights_complaint' => 'If you believe your data rights have been violated, you have the right to lodge a complaint with a supervisory authority, such as the Bavarian State Office for Data Protection Supervision (BayLDA).',

    // ── Data Retention ─────────────────────────────────
    'heading_data_retention' => 'Data Retention',
    'content_retention_intro' => 'We keep your data only as long as necessary:',
    'content_retention_account' => 'Account data: Retained while your account is active. Deleted within 30 days of account deletion, unless legal obligations require longer retention.',
    'content_retention_activity' => 'Activity data: Attendance records and reliability scores are retained for the lifetime of the account to maintain scoring accuracy.',
    'content_retention_analytics' => 'Analytics data: PostHog data is retained for up to 13 months, then automatically deleted.',
    'content_retention_legal' => 'Legal requirements: Financial records and association membership data are retained as required by German law (typically 6–10 years).',

    // ── Contact ────────────────────────────────────────
    'heading_contact' => 'Contact',
    'content_contact_intro' => 'For any questions about this Privacy Policy or your personal data, contact us:',
    'content_contact_org' => config('company.legal_name'),
    'content_contact_email' => 'Email: ' . config('company.contact.privacy'),

    // ── Last Updated ───────────────────────────────────
    'content_last_updated' => 'Last updated: :date',
];
