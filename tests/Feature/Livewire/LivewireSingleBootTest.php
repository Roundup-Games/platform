<?php

use App\Models\User;

//
// Pins the single-boot frontend-asset posture (CWV manual-bundle pattern,
// commit 30dc5171 + the 2026-11-20 incident fix): the Livewire+Alpine
// runtime is loaded EXACTLY ONCE, through the Vite module bundle. The app
// layout originally shipped without @livewireScriptConfig, and with
// Livewire's inject_assets default (true) every authenticated page — the
// app layout always renders Livewire components — auto-injected its
// synchronous livewire.min.js NEXT TO the bundled runtime. The double
// boot died with "can't redefine non-configurable property '$persist'"
// and every Alpine/Livewire surface (settingsTabs, copyButton…) stayed
// blank until a lucky refresh; guest pages render no components, which
// is why the bug only showed on authed surfaces.
//

it('serves the settings page with the manual Livewire config and no auto-injected runtime', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    $response = $this->actingAs($user)->get('/en/settings');

    $response->assertOk();

    // The config the bundled runtime reads — must be inline in <head>
    // before the deferred module executes.
    expect($response->getContent())->toContain('window.livewireScriptConfig');

    // The auto-injected synchronous runtime must be absent — exactly one
    // Livewire/Alpine boot per page. (Would fail before the fix: every
    // authed response carried <script src=".../livewire.min.js">.)
    expect($response->getContent())->not->toContain('livewire.min.js');
});

it('serves the public landing page with the same single-boot posture', function () {
    $response = $this->get('/en');

    $response->assertOk();

    // Public layout carried the config from day one; pin both halves so
    // neither layout can silently regress to the auto-injected runtime.
    expect($response->getContent())->not->toContain('livewire.min.js');
});

it('ships the Livewire runtime styles in the app CSS — inject_assets=false stops the auto-injected stylesheet too', function () {
    // The Vite bundle carries the JS runtime only; Livewire's JS never
    // self-injects these rules. With injection off, our own CSS is the
    // only place they can live — without them every wire:loading element
    // (spinners, "saving…" labels) renders visible at rest (live
    // 2026-11-20: Settings → Linked Accounts showed perpetual loading
    // states while every action worked).
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    expect($css)->toContain('[wire\\:loading][wire\\:loading]')
        ->and($css)->toContain('[wire\\:offline][wire\\:offline]')
        ->and($css)->toContain('[wire\\:dirty]:not(textarea):not(input):not(select)');
});
