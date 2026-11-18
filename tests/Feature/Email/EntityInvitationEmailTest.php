<?php

use App\Mail\EntityInvitationEmail;
use App\Models\Game;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;

// ═══════════════════════════════════════════════════════════
// Guest invitation mail — structural rendering pins (M081
// sixth-pass review): the first-impression mail carries the
// wrapper-identical hidden preheader, placed BEFORE the brand
// header (the published mail::message override renders it as the
// first row of the header slot), and renders in the invited-to
// entity's language, not the queue default.
// ═══════════════════════════════════════════════════════════

it('renders the guest invitation with the hidden preheader ahead of the brand header', function () {
    $game = Game::factory()->make(['name' => ['en' => 'The Amber Citadel', 'de' => 'Die Bernstein-Zitadelle']]);
    $host = User::factory()->make(['name' => 'Hosty McHost']);

    $mail = new EntityInvitationEmail(
        entityType: 'game',
        entityName: 'The Amber Citadel',
        entityDateTime: Carbon::parse('+7 days 18:00'),
        entityLocation: 'The Rusty Tankard',
        inviterName: $host->name,
        inviteeEmail: 'guest@example.com',
        signupUrl: 'https://roundup.test/signup',
    );

    $html = $mail->render();

    $preheader = __('emails.preheader_entity_invitation', ['entity' => 'The Amber Citadel'], 'en');
    $preheaderPos = strpos($html, e($preheader));
    $brandHeaderPos = strpos($html, 'td class="header"');
    // Heading match on a stable fragment: the apostrophe in the full key
    // HTML-encodes differently through the markdown pipeline than e().
    $headingPos = strpos($html, 'Invited to a Game');

    expect($preheaderPos)->not->toBeFalse('rendered invitation mail does not contain the preheader text');
    expect($brandHeaderPos)->not->toBeFalse('rendered invitation mail does not contain the brand header row');
    expect($headingPos)->not->toBeFalse('rendered invitation mail does not contain the heading');
    expect($preheaderPos)->toBeLessThan($brandHeaderPos, 'preheader must render before the brand header row (hidden inbox-preview slot)');
    expect($preheaderPos)->toBeLessThan($headingPos, 'preheader must render before the heading (hidden inbox-preview slot)');
});

it('renders the preheader in the invited-to entity\'s language', function () {
    App::setLocale('en');

    $mail = new EntityInvitationEmail(
        entityType: 'game',
        entityName: 'Die Bernstein-Zitadelle',
        entityDateTime: null,
        entityLocation: null,
        inviterName: 'Hosty McHost',
        inviteeEmail: 'guest@example.com',
        signupUrl: 'https://roundup.test/signup',
        entityLanguage: 'de',
    );

    $html = $mail->render();

    $preheader = __('emails.preheader_entity_invitation', ['entity' => 'Die Bernstein-Zitadelle'], 'de');
    expect($html)->toContain(e($preheader))
        ->not->toContain(e(__('emails.preheader_entity_invitation', ['entity' => 'Die Bernstein-Zitadelle'], 'en')));
});
