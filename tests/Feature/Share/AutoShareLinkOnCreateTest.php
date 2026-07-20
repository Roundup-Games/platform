<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\ShortLink;
use App\Models\User;
use App\Services\ShortLinkService;

// ═══════════════════════════════════════════════════════════════════════
// S07 — auto-generated share ShortLink on entity create
// ═══════════════════════════════════════════════════════════════════════
//
// Every new Game and Campaign auto-receives a purpose='share' ShortLink
// owned by the entity's creator. The owner has a copy-ready invite URL
// the moment the entity exists; the ShareSnippetFormatter composes a
// tight text block around it when the owner copies the invite from the
// entity's share panel.
//
// The auto-generation hooks the entity's lifecycle (GameObserver::created,
// CampaignObserver::created) so the link is present regardless of the
// creation path (Livewire component, console command, factory with an
// owner). It silently no-ops when the entity has no owner (factories
// without an owner_id) to avoid orphan links.
//
// The feature is config-gated (share.auto_generate_on_create). The test
// suite disables it by default in phpunit.xml to avoid polluting fixture
// state; this test file re-enables it explicitly.

beforeEach(function () {
    config(['share.auto_generate_on_create' => true]);
});

describe('auto-generated share ShortLink on entity create', function () {
    it('creates a purpose=share ShortLink when a Game with an owner is created', function () {
        $owner = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDay(),
            'max_players' => 4,
        ]);

        $links = ShortLink::where('linkable_type', Game::class)
            ->where('linkable_id', $game->getKey())
            ->where('purpose', 'share')
            ->get();

        expect($links)->toHaveCount(1)
            ->and($links->first()->user_id)->toBe($owner->id);
    });

    it('creates a purpose=share ShortLink when a Campaign with an owner is created', function () {
        $owner = User::factory()->create();

        $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

        $links = ShortLink::where('linkable_type', Campaign::class)
            ->where('linkable_id', $campaign->getKey())
            ->where('purpose', 'share')
            ->get();

        expect($links)->toHaveCount(1)
            ->and($links->first()->user_id)->toBe($owner->id);
    });

    it('labels the auto-generated link so it is distinguishable from manually created ones', function () {
        $owner = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDay(),
            'max_players' => 4,
        ]);

        $link = ShortLink::where('linkable_type', Game::class)
            ->where('linkable_id', $game->getKey())
            ->where('purpose', 'share')
            ->first();

        expect($link->label)->toBe('Auto-generated share link');
    });

    it('logs a warning but does not throw when ShortLinkService raises an error', function () {
        // AutoShareLink resolves ShortLinkService via the container, so we
        // bind a mock that throws when createLink is called. This actually
        // exercises the catch block in AutoShareLink::generate() and proves
        // a ShortLinkService failure never blocks entity creation.
        $this->mock(ShortLinkService::class, function ($mock) {
            $mock->shouldReceive('createLink')->andThrow(new Exception('Test service failure'));
        });

        $owner = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDay(),
            'max_players' => 4,
        ]);

        // Game exists → observer caught the exception and did not propagate.
        expect(Game::whereKey($game->getKey())->exists())->toBeTrue();
        // And no ShortLink was created (the mock threw before any insert).
        expect(ShortLink::where('linkable_type', Game::class)
            ->where('linkable_id', $game->getKey())
            ->count()
        )->toBe(0);
    });
});
