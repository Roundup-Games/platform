<?php

use App\Models\ShortLink;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Entity terminology hygiene (M066/S02/T07)
|--------------------------------------------------------------------------
|
| Developer jargon must not reach users: no "Entity" column header, no
| "for this entity" error copy, and never a class_basename() fallback in
| the short-link table. Share links always belong to a game or a campaign
| (ManagesShortLinks is used by GameDetail/CampaignDetail only), so the
| UI names those. DE follows the glossary canon: Game = Runde,
| Campaign = Kampagne.
|
*/

function t07_renderShareLinksManager(ShortLink $link): string
{
    return view('livewire.partials._share-links-manager', [
        'links' => collect([$link]),
        'showActions' => false,
    ])->render();
}

it('labels the short-link target column without entity jargon', function () {
    app()->setLocale('en');

    $link = ShortLink::factory()->make(['label' => null, 'linkable_id' => null]);
    $html = t07_renderShareLinksManager($link);

    expect($html)
        ->toContain('Game or campaign')
        ->not->toContain('>Entity<');

    app()->setLocale('de');

    expect(t07_renderShareLinksManager($link))
        ->toContain('Runde oder Kampagne')
        ->not->toContain('Entität');
});

it('falls back to the localized type label for orphaned links, never a class name', function (string $locale, string $label) {
    app()->setLocale($locale);

    // Orphaned: linkable_id points at no row, so $link->linkable is null and
    // the type-fallback branch renders (previously: class_basename('game')).
    $link = ShortLink::factory()->make([
        'label' => null,
        'linkable_type' => 'game',
        'linkable_id' => Str::uuid()->toString(),
    ]);

    $html = t07_renderShareLinksManager($link);

    expect($html)
        ->toContain('>'.$label.'</span>')
        ->not->toContain('>game<');
})->with([
    'en' => ['en', 'Game'],
    'de' => ['de', 'Spiel'],
]);

it('words the short-link limit error in plain user-facing language', function () {
    app()->setLocale('en');

    expect(__('common.error_max_links_reached'))
        ->toContain('game or campaign')
        ->not->toContain('entity');

    app()->setLocale('de');

    expect(__('common.error_max_links_reached'))
        ->toContain('Runde oder Kampagne')
        ->not->toContain('Entität');
});
