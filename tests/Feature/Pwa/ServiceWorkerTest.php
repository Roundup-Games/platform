<?php

describe('Service Worker', function () {
    it('exists as a JavaScript file in public/', function () {
        $path = public_path('sw.js');

        expect(file_exists($path))->toBeTrue('sw.js should exist in public/');
        expect(mime_content_type($path))->toContain('text');
    });

    it('defines CACHE_NAME constant', function () {
        $content = file_get_contents(public_path('sw.js'));

        expect($content)->toContain("const CACHE_NAME = 'roundup-v1'");
    });

    it('registers install event handler', function () {
        $content = file_get_contents(public_path('sw.js'));

        expect($content)->toContain("self.addEventListener('install'");
    });

    it('registers fetch event handler', function () {
        $content = file_get_contents(public_path('sw.js'));

        expect($content)->toContain("self.addEventListener('fetch'");
    });

    it('registers activate event handler', function () {
        $content = file_get_contents(public_path('sw.js'));

        expect($content)->toContain("self.addEventListener('activate'");
    });

    it('uses network-first strategy for HTML requests', function () {
        $content = file_get_contents(public_path('sw.js'));

        expect($content)->toContain('text/html');
        expect($content)->toContain('networkFirst');
    });

    it('uses cache-first strategy for static assets', function () {
        $content = file_get_contents(public_path('sw.js'));

        expect($content)->toContain('cacheFirst');
        expect($content)->toContain('HASHED_ASSET_RE');
    });

    it('excludes Livewire routes from caching', function () {
        $content = file_get_contents(public_path('sw.js'));

        expect($content)->toContain('/livewire');
        expect($content)->toContain('X-Livewire');
    });

    it('includes offline fallback page in pre-cache', function () {
        $content = file_get_contents(public_path('sw.js'));

        expect($content)->toContain('/offline.html');
    });
});
