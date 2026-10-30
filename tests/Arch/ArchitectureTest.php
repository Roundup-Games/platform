<?php

/**
 * Architecture guardrails (adopted from the Traceway Laravel 13 writeup, r/laravel
 * 1xgm9el). Same philosophy as scripts/check_eloquent_practices.sh: high-precision,
 * zero false positives, pinned to zero baseline violations. CI-gating via the Pest
 * suite. When a rule blocks legitimate work, extend the documented ignore list in
 * the same change and leave a comment saying why — never weaken the rule.
 */
arch('value objects are dependency-free')
    ->expect('App\Values')
    ->toUseNothing()
    // The __() translation helper is the only sanctioned global function.
    ->ignoring('__');

arch('enums stay dependency-light')
    ->expect('App\Enums')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Support\Facades',
        'App\Models',
        'App\Services',
        'App\Http',
        'App\Livewire',
        'App\Filament',
        'App\Console',
    ])
    // Pre-existing stragglers: dashboard cache coupling and Discord RSVP side
    // effects. Ignore-listed, not refactored, until they are deliberately moved.
    ->ignoring([
        'App\Enums\DashboardSection',
        'App\Enums\DiscordCardStatus',
        'App\Enums\DiscordRsvpOutcome',
    ]);

arch('dto shapes stay free of presentation and transport layers')
    ->expect('App\Dto')
    ->not->toUse([
        'App\Http',
        'App\Livewire',
        'App\Filament',
        'App\Console',
    ]);

arch('contracts stay free of presentation and transport layers')
    ->expect('App\Contracts')
    ->not->toUse([
        'App\Http',
        'App\Livewire',
        'App\Filament',
        'App\Console',
    ]);

arch('outbound HTTP is confined to client classes')
    ->expect('App')
    ->not->toUse('Illuminate\Support\Facades\Http')
    // Sanctioned egress: dedicated client classes under App\Services\<Provider>
    // and App\SEO. Stragglers below are deliberate exceptions (OAuth callback
    // flow, Cloudflare cache-rules sync) — see .ai/rules/app.md. New
    // outbound calls must go through a client class, never join this list silently.
    ->ignoring([
        'App\Services',
        'App\SEO',
        'App\Http\Controllers\Auth\OAuthController',
        'App\Console\Commands\SyncCloudflareCacheRules',
    ]);
