<?php

use App\Enums\ContentLanguage;
use Illuminate\Support\Facades\Blade;

describe('LanguageMismatchBanner Blade Component', function () {

    it('renders when entity language differs from UI locale', function () {
        app()->setLocale('en');

        $html = Blade::render(
            '<x-language-mismatch-banner :entity-language="$lang" />',
            ['lang' => 'de']
        );

        expect($html)->toContain('German');
        expect($html)->toContain('differs from your current language setting');
        expect($html)->toContain('x-data');
        expect($html)->toContain('x-show');
        expect($html)->toContain('role="alert"');
        expect($html)->toContain('translate');
    });

    it('does not render when entity language matches UI locale', function () {
        app()->setLocale('en');

        $html = Blade::render(
            '<x-language-mismatch-banner :entity-language="$lang" />',
            ['lang' => 'en']
        );

        expect(trim($html))->toBe('');
    });

    it('does not render when entity language is empty', function () {
        app()->setLocale('en');

        $html = Blade::render(
            '<x-language-mismatch-banner :entity-language="$lang" />',
            ['lang' => '']
        );

        expect(trim($html))->toBe('');
    });

    it('renders with German UI and English entity', function () {
        app()->setLocale('de');

        $html = Blade::render(
            '<x-language-mismatch-banner :entity-language="$lang" />',
            ['lang' => 'en']
        );

        expect($html)->toContain('English');
        expect($html)->toContain('Sprachauswahl');
    });

    it('includes dismiss button with close icon', function () {
        app()->setLocale('en');

        $html = Blade::render(
            '<x-language-mismatch-banner :entity-language="$lang" />',
            ['lang' => 'de']
        );

        expect($html)->toContain('x-on:click="visible = false"');
        expect($html)->toContain('close');
        expect($html)->toContain('aria-label');
    });

    it('uses design system tokens', function () {
        app()->setLocale('en');

        $html = Blade::render(
            '<x-language-mismatch-banner :entity-language="$lang" />',
            ['lang' => 'de']
        );

        expect($html)->toContain('bg-surface-container-high');
        expect($html)->toContain('text-on-surface');
        expect($html)->toContain('rounded-xl');
    });

    it('ContentLanguage label returns correct names', function () {
        expect(ContentLanguage::En->label())->toBe('English');
        expect(ContentLanguage::De->label())->toBe('German');
    });
});
