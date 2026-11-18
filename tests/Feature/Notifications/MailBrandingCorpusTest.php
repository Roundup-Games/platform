<?php

namespace Tests\Feature\Notifications;

use Tests\Traits\RendersBrandedCorpusMail;

/*
|--------------------------------------------------------------------------
| Branded mail corpus proof (M081/S01/T01, D279/R103)
|--------------------------------------------------------------------------
|
| Renders a representative corpus subset through the real send surfaces
| (container MailChannel for notifications, Mail::send for mailables) in
| both locales and pins the theme contract on the captured transport
| bodies:
|
|   - the literal brand token #835500 from the inlined roundup theme,
|   - the light-lock contract (2026-11-16): the delivered HTML declares
|     color-scheme "light" and carries NO prefers-color-scheme block —
|     client-side dark recoloring (Mailpit + dark-mode extensions) collided
|     with the former embedded dark block into illegible hybrids,
|   - the :focus-visible keyboard-accessibility rule,
|   - the html lang attribute following the render locale (regression
|     pin: mail::html layout uses app()->getLocale()).
|
| Structural CSS/HTML assertions are literal by design; localized copy
| is not asserted here (the corpus render test owns copy coverage).
|
*/

uses(RendersBrandedCorpusMail::class);

/**
 * Corpus subset × locale. Mail classes only: the corpus registry's
 * mail-bearing classes plus two app/Mail mailables rendering through the
 * same markdown theme.
 *
 * @return array<string, array{string, string}>
 */
function mailBrandingDataset(): array
{
    $dataset = [];

    foreach (
        [
            'notification/WaitlistPromoted',
            'notification/EntityCancelled',
            'notification/WeeklyDigest',
            'notification/BulletinPosted',
            'mailable/WelcomeEmail',
            'mailable/EntityInvitationEmail',
        ] as $case
    ) {
        foreach (['en', 'de'] as $locale) {
            $dataset["{$case}/{$locale}"] = [$case, $locale];
        }
    }

    return $dataset;
}

it('renders branded corpus mail with the amber theme, light-lock contract, focus styles, locale-following lang, and a clean text part', function (string $case, string $locale) {
    $bodies = $this->sendBrandedCorpusMail($case, $locale);

    test()->assertStringContainsString('#835500', $bodies['html'], "[{$case}/{$locale}] brand amber #835500 from the inlined theme is missing.");
    test()->assertStringContainsString('<meta name="color-scheme" content="light">', $bodies['html'], "[{$case}/{$locale}] light-only color-scheme declaration is missing.");
    test()->assertStringNotContainsString('prefers-color-scheme', $bodies['html'], "[{$case}/{$locale}] a dark media query survived the light-lock in the delivered <style> block.");
    test()->assertStringContainsString('focus-visible', $bodies['html'], "[{$case}/{$locale}] keyboard-focus rule is missing.");
    test()->assertStringContainsString(sprintf('lang="%s"', $locale), $bodies['html'], "[{$case}/{$locale}] html lang attribute does not follow the render locale.");

    test()->assertNotSame('', trim($bodies['text']), "[{$case}/{$locale}] text part is empty.");
    test()->assertStringNotContainsString('<', $bodies['text'], "[{$case}/{$locale}] text part contains HTML markup.");
    test()->assertStringNotContainsString('&lt;', $bodies['text'], "[{$case}/{$locale}] text part contains escaped HTML markup.");
})->with(mailBrandingDataset());
