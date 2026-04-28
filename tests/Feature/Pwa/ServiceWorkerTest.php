<?php

describe('Service Worker', function () {
    it('exists as a JavaScript file in public/', function () {
        $path = public_path('sw.js');

        expect(file_exists($path))->toBeTrue('sw.js should exist in public/');
        expect(mime_content_type($path))->toContain('text');
    });

    it('defines dynamic CACHE_NAME derived from manifest hash', function () {
        $content = file_get_contents(public_path('sw.js'));

        expect($content)->toContain("let CACHE_NAME = 'roundup-static'");
        expect($content)->toContain('CACHE_NAME = \'roundup-\' + Math.abs(hash).toString(36)');
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

    it('registers push event handler', function () {
        $content = file_get_contents(public_path('sw.js'));

        expect($content)->toContain("self.addEventListener('push'");
    });

    it('registers notificationclick event handler', function () {
        $content = file_get_contents(public_path('sw.js'));

        expect($content)->toContain("self.addEventListener('notificationclick'");
    });

    it('has offline action queue IndexedDB helpers', function () {
        $content = file_get_contents(public_path('sw.js'));

        expect($content)->toContain('OFFLINE_DB');
        expect($content)->toContain('openQueueDB');
    });

    it('uses stale-while-revalidate for geocode API', function () {
        $content = file_get_contents(public_path('sw.js'));

        expect($content)->toContain('/api/geocode');
        expect($content)->toContain('staleWhileRevalidate');
    });

    it('cleans up old caches on activate', function () {
        $content = file_get_contents(public_path('sw.js'));

        expect($content)->toContain('caches.keys()');
        expect($content)->toContain("self.addEventListener('activate'");

        // Verify that old caches are deleted (not just listed)
        $activatePos = strpos($content, "self.addEventListener('activate'");
        $this->assertGreaterThan(0, $activatePos);

        // Find the activate handler block — should contain cache deletion
        $afterActivate = substr($content, $activatePos, 500);
        expect($afterActivate)->toContain('caches.delete');
    });
});
