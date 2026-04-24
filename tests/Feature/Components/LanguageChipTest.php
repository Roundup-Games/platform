<?php

use App\Enums\ContentLanguage;
use Illuminate\Support\Facades\Blade;

describe('LanguageChip Blade Component', function () {

    it('renders a chip for a valid language code', function () {
        $html = Blade::render(
            '<x-language-chip :language="$lang" />',
            ['lang' => 'en']
        );

        expect($html)->toContain('EN');
        expect($html)->toContain('translate');
        expect($html)->toContain('rounded-full');
    });

    it('renders German language chip', function () {
        $html = Blade::render(
            '<x-language-chip :language="$lang" />',
            ['lang' => 'de']
        );

        expect($html)->toContain('DE');
        expect($html)->toContain('translate');
    });

    it('does not render for invalid language code', function () {
        $html = Blade::render(
            '<x-language-chip :language="$lang" />',
            ['lang' => 'fr']
        );

        expect(trim($html))->toBe('');
    });

    it('does not render for empty language', function () {
        $html = Blade::render(
            '<x-language-chip :language="$lang" />',
            ['lang' => '']
        );

        expect(trim($html))->toBe('');
    });

    it('uses design system tokens', function () {
        $html = Blade::render(
            '<x-language-chip :language="$lang" />',
            ['lang' => 'en']
        );

        expect($html)->toContain('bg-surface-container-high');
        expect($html)->toContain('text-on-surface-variant');
        expect($html)->toContain('text-xs');
        expect($html)->toContain('font-medium');
    });

    it('renders as inline chip element', function () {
        $html = Blade::render(
            '<x-language-chip :language="$lang" />',
            ['lang' => 'en']
        );

        expect($html)->toContain('<span');
        expect($html)->toContain('inline-flex');
    });

    it('uses uppercase language code', function () {
        $html = Blade::render(
            '<x-language-chip :language="$lang" />',
            ['lang' => 'de']
        );

        expect($html)->toContain('DE');
        expect($html)->not->toContain('de</span>');
    });
});

describe('LanguageChip in Card Templates', function () {

    it('renders language chip inside discovery game card', function () {
        $game = (object) [
            'id' => 1,
            'name' => 'Test Game',
            'language' => 'de',
            'price' => 0,
            'date_time' => now()->addDays(7),
            'expected_duration' => 3,
            'description' => 'A test game',
            'visibility' => 'public',
            'experience_level' => null,
            'min_players' => 2,
            'max_players' => 6,
            'participants_count' => 3,
            'vibe_flags' => [],
            'gameSystem' => null,
            'distance_km' => null,
            'campaign' => null,
        ];

        app()->setLocale('en');

        $html = Blade::render(
            '<x-language-chip :language="$game->language" />',
            ['game' => $game]
        );

        expect($html)->toContain('DE');
        expect($html)->toContain('translate');
    });

    it('renders language chip inside event card context', function () {
        app()->setLocale('en');

        $html = Blade::render(
            '<x-language-chip :language="$language" />',
            ['language' => 'en']
        );

        expect($html)->toContain('EN');
    });
});
