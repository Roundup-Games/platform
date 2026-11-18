<?php

namespace Tests\Feature\Notifications;

use Tests\Traits\RendersBrandedCorpusMail;

/*
|--------------------------------------------------------------------------
| R103 email-safe CSS enforcement (M081/S01/T01)
|--------------------------------------------------------------------------
|
| Machine lint for the client-compatibility half of R103. Two subjects:
|
|   1. the theme file itself (resources/views/vendor/mail/html/themes/
|      roundup.css) — the source the renderer inlines and the published
|      layout embeds verbatim;
|   2. the rendered corpus subset (same fixture pattern as the branding
|      corpus test) — both the inlined style attributes and the embedded
|      <style> block, so a violation introduced by any view in the chain
|      fails here.
|
| Blocked patterns are the R103 subset: flex/grid layout, absolute
| positioning, CSS custom properties, @import, @font-face, remote font
| urls, animations, transitions, and <script>. CSS comments are stripped
| before linting — the theme's own R103 header names these tokens.
|
| The human Mailpit check (caniemail data per client, Gmail/Apple Mail/
| Outlook/Thunderbird) runs at slice UAT per docs/notification-verification.
| md §6; this lint is the machine gate that the corpus can never drift
| outside the subset in the first place.
|
*/

uses(RendersBrandedCorpusMail::class);

/**
 * R103-blocked patterns, each labeled for the failure message.
 *
 * @return array<string, string>
 */
function mailCompatibilityBlockedPatterns(): array
{
    return [
        'display:flex/grid layout' => '/display\s*:\s*(?:inline-)?(?:flex|grid)\b/i',
        'position:fixed/absolute' => '/position\s*:\s*(?:fixed|absolute)\b/i',
        'CSS custom properties var(--…)' => '/var\s*\(\s*--/',
        '@import' => '/@import\b/i',
        '@font-face' => '/@font-face\b/i',
        'remote font url (woff/ttf/otf)' => '/url\s*\([^)]*\.(?:woff2?|ttf|otf)/i',
        'animation:' => '/\banimation(?:-name|-duration|-iteration-count|-timing-function)?\s*:/i',
        'transition:' => '/\btransition(?:-property|-duration|-timing-function)?\s*:/i',
        '<script' => '/<script/i',
    ];
}

/**
 * Strip /* … *​/ CSS comments — the theme documents the R103 subset by
 * naming the blocked tokens, which the lint must not match.
 */
function mailCompatibilityStripCssComments(string $subject): string
{
    return (string) preg_replace('~/\*.*?\*/~s', '', $subject);
}

/**
 * Remove every prefers-color-scheme @media block (one nesting level,
 * same shape the CssToInlineStyles cleanup regex handles) so the
 * remaining rules are the light-mode base every client renders.
 */
function mailCompatibilityWithoutDarkMediaBlock(string $subject): string
{
    return (string) preg_replace(
        '/@media[^{}]*?prefers-color-scheme[^{}]*+\{(?:[^{}]++|\{[^{}]*+\})*+\}/',
        '',
        $subject
    );
}

it('keeps the theme file inside the R103 email-safe subset', function () {
    $css = mailCompatibilityStripCssComments(
        (string) file_get_contents(resource_path('views/vendor/mail/html/themes/roundup.css'))
    );

    foreach (mailCompatibilityBlockedPatterns() as $label => $pattern) {
        test()->assertSame(0, preg_match($pattern, $css), "Theme CSS violates R103: {$label}.");
    }

    $base = mailCompatibilityWithoutDarkMediaBlock($css);

    // Outlook's Word engine ignores media queries: the light base must be
    // complete on its own — cream surface, dark ink text, amber accent.
    test()->assertStringContainsString('#fbf9f1', $base, 'Cream surface missing from the light-mode base rules.');
    test()->assertStringContainsString('#3d3a2e', $base, 'Dark ink body text missing from the light-mode base rules.');
    test()->assertStringContainsString('#835500', $base, 'Amber accent missing from the light-mode base rules.');
    // Light-lock (2026-11-16): no dark media query may exist anywhere in the
    // theme — client-side dark recoloring (e.g. dark-mode extensions over
    // Mailpit) collided with the former embedded dark block and produced
    // illegible near-white-on-cream hybrids. The light base is the whole
    // design now; no !important color overrides are needed or allowed.
    test()->assertStringNotContainsString('prefers-color-scheme', $css, 'A prefers-color-scheme block survived the light-lock.');
    test()->assertSame(
        0,
        preg_match('/(?:background-)?color\s*:[^;}]*!important/', $css),
        'Theme CSS color rules must not use !important — nothing overrides inline attributes anymore.'
    );
});

it('renders the corpus mail without R103-blocked client features and with the table wrapper intact', function (string $case, string $locale) {
    $bodies = $this->sendBrandedCorpusMail($case, $locale);

    $lintable = mailCompatibilityStripCssComments($bodies['html']);

    foreach (mailCompatibilityBlockedPatterns() as $label => $pattern) {
        test()->assertSame(0, preg_match($pattern, $lintable), "[{$case}/{$locale}] rendered mail violates R103: {$label}.");
    }

    // Table-based layout survives: the framework wrapper table (never a
    // div-only restructure) reaches the delivered HTML.
    test()->assertStringContainsString('<table class="wrapper"', $lintable, "[{$case}/{$locale}] framework table wrapper missing from rendered mail.");
})->with(
    collect([
        'notification/WaitlistPromoted',
        'notification/EntityCancelled',
        'notification/WeeklyDigest',
        'notification/BulletinPosted',
        'mailable/WelcomeEmail',
        'mailable/EntityInvitationEmail',
    ])
        ->crossJoin(['en', 'de'])
        ->mapWithKeys(fn (array $pair): array => [implode('/', $pair) => $pair])
        ->all()
);

it('carries a fully readable light-mode base outside the dark media query in rendered mail', function (string $case) {
    $bodies = $this->sendBrandedCorpusMail($case, 'en');

    // Strip comments, then remove ONLY the dark block: what remains is
    // exactly what clients without media-query support render (plus the
    // inlined style attributes, which the renderer built from the
    // media-stripped base rules).
    $base = mailCompatibilityWithoutDarkMediaBlock(
        mailCompatibilityStripCssComments($bodies['html'])
    );

    test()->assertStringContainsString('#fbf9f1', $base, "[{$case}] cream card surface must exist outside the dark media query.");
    test()->assertStringContainsString('#3d3a2e', $base, "[{$case}] dark ink body text must exist outside the dark media query.");
    test()->assertStringNotContainsString('prefers-color-scheme', $base, "[{$case}] a dark media query survived the strip — nested braces exceeded the supported depth.");
})->with([
    'notification/WaitlistPromoted',
    'notification/EntityCancelled',
    'notification/WeeklyDigest',
    'notification/BulletinPosted',
    'mailable/WelcomeEmail',
    'mailable/EntityInvitationEmail',
]);

it('renders the mail salutation and action subcopy in the mail locale, not framework English', function (string $locale) {
    // The published notifications wrapper's salutation and trouble-clicking
    // subcopy were framework @lang literal strings — those only resolve via
    // JSON translation files this project does not ship, so every non-English
    // mail rendered English there. Both now resolve through group keys
    // (notifications.email_regards / email_trouble_clicking).
    $bodies = $this->sendBrandedCorpusMail('notification/WaitlistPromoted', $locale);

    test()->assertStringContainsString(__('notifications.email_regards', [], $locale), $bodies['html'], "[{$locale}] salutation must render through the notifications group key.");

    // The text part soft-wraps long lines — compare whitespace-normalized
    // forms so the assertion is wrap-position-agnostic.
    $normalize = fn (string $value): string => (string) preg_replace('/\s+/u', ' ', $value);
    $subcopy = __('notifications.email_trouble_clicking', ['actionText' => __('notifications.action_waitlist_promoted', [], $locale)], $locale);

    test()->assertStringContainsString($normalize($subcopy), $normalize($bodies['text']), "[{$locale}] action subcopy must render through the notifications group key.");
})->with(['en', 'de']);
