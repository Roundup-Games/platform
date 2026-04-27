<?php

describe('Web Manifest', function () {
    it('exists as a valid JSON file', function () {
        $path = public_path('manifest.json');

        expect(file_exists($path))->toBeTrue('manifest.json should exist in public/');

        $manifest = json_decode(file_get_contents($path), true);
        expect($manifest)->not->toBeNull('manifest.json should be valid JSON');
    });

    it('contains all required PWA fields', function () {
        $manifest = json_decode(file_get_contents(public_path('manifest.json')), true);

        expect($manifest)->toHaveKeys([
            'name',
            'short_name',
            'start_url',
            'display',
            'theme_color',
            'background_color',
            'icons',
        ]);
    });

    it('has correct display mode', function () {
        $manifest = json_decode(file_get_contents(public_path('manifest.json')), true);

        expect($manifest['display'])->toBe('standalone');
    });

    it('matches the design system theme color', function () {
        $manifest = json_decode(file_get_contents(public_path('manifest.json')), true);

        expect($manifest['theme_color'])->toBe('#835500');
    });

    it('includes 192x192 and 512x512 icon entries', function () {
        $manifest = json_decode(file_get_contents(public_path('manifest.json')), true);
        $sizes = array_column($manifest['icons'], 'sizes');

        expect($sizes)->toContain('192x192');
        expect($sizes)->toContain('512x512');
    });

    it('icons have valid src and type', function () {
        $manifest = json_decode(file_get_contents(public_path('manifest.json')), true);

        foreach ($manifest['icons'] as $icon) {
            expect($icon)->toHaveKey('src');
            expect($icon)->toHaveKey('type');
            expect($icon['type'])->toBe('image/png');
        }
    });
});
