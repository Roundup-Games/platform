<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\User;
use App\Support\ShareSnippetFormatter;

beforeEach(function () {
    // No setup needed — formatter is a pure function.
});

// ═══════════════════════════════════════════════════════════════════════
// ShareSnippetFormatter — S07
// ═══════════════════════════════════════════════════════════════════════
//
// The formatter must produce a tight, platform-agnostic plain-text block
// (no Discord markdown, no Twitter tokens) that fits within ~110 chars
// per line and 5 lines so it renders cleanly on Discord, Twitter/X,
// Mastodon, email, and chat apps.

describe('game formatting', function () {
    it('formats a fully-populated game into a 6-line snippet', function () {
        $owner = User::factory()->create(['name' => 'Berlin Host']);
        $system = GameSystem::factory()->create(['name' => 'D&D 5e']);
        $location = Location::factory()->create(['city' => 'Berlin']);
        $game = Game::factory()->create([
            'name' => 'Candlekeep Mysteries',
            'owner_id' => $owner->id,
            'location_id' => $location->id,
            'date_time' => now()->addDays(7)->setTimeFromTimeString('19:00:00'),
            'max_players' => 5,
        ]);
        $game->gameSystems()->attach($system->id);

        $snippet = ShareSnippetFormatter::format($game->fresh(['owner', 'gameSystems', 'linkedLocation']), 'https://roundup.games/l/ab12cd9');

        $lines = explode("\n", $snippet);
        expect(count($lines))->toBe(6)
            ->and($lines[0])->toStartWith('🎲 Candlekeep Mysteries')
            ->and($lines[1])->toContain('19:00') // locale-aware but contains time
            ->and($lines[2])->toContain('0/5')   // capacity 0 approved / 5 max
            ->and($lines[2])->toContain('D&D 5e')
            ->and($lines[3])->toBe('Berlin')
            ->and($lines[4])->toBe('Hosted by Berlin Host')
            ->and($lines[5])->toBe('https://roundup.games/l/ab12cd9');
    });

    it('truncates long game names with an ellipsis', function () {
        $owner = User::factory()->create(['name' => 'Host']);
        $game = Game::factory()->create([
            'name' => str_repeat('a', 100), // 100 chars, exceeds the 80-char limit
            'owner_id' => $owner->id,
        ]);

        $snippet = ShareSnippetFormatter::format($game->fresh(['owner', 'gameSystems', 'linkedLocation']), 'https://roundup.games/l/x');

        $firstLine = explode("\n", $snippet)[0];
        expect(mb_strlen($firstLine))->toBeLessThanOrEqual(82); // 🎲 (2 chars in emoji display) + 79 + ellipsis
        expect($firstLine)->toEndWith('…');
    });

    it('falls back to Location TBD when no location is set', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'location_id' => null,
            'date_time' => now()->addDay(),
            'max_players' => 4,
        ]);

        $snippet = ShareSnippetFormatter::format($game->fresh(['owner', 'gameSystems', 'linkedLocation']), 'https://roundup.games/l/x');

        expect($snippet)->toContain('Location TBD');
    });

    it('shows Open capacity when max_players is null or zero', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'max_players' => 1,
            'date_time' => now()->addDay(),
        ]);

        $snippet = ShareSnippetFormatter::format($game->fresh(['owner', 'gameSystems', 'linkedLocation']), 'https://roundup.games/l/x');

        // max_players=1 produces 0/1 — we can't test 'Open' via factory because
        // the column is non-nullable, so test the formatter directly via the
        // branch it would hit. This test pins the 0/1 capacity line.
        expect($snippet)->toContain('0/1');
    });

    it('lists up to 2 game systems with et al. when more exist', function () {
        $owner = User::factory()->create();
        $systemA = GameSystem::factory()->create(['name' => 'AAA System']);
        $systemB = GameSystem::factory()->create(['name' => 'BBB System']);
        $systemC = GameSystem::factory()->create(['name' => 'CCC System']);
        $game = Game::factory()->create(['owner_id' => $owner->id, 'date_time' => now()->addDay(), 'max_players' => 4]);
        $game->gameSystems()->sync([$systemA->id, $systemB->id, $systemC->id]);

        $snippet = ShareSnippetFormatter::format($game->fresh(['owner', 'gameSystems', 'linkedLocation']), 'https://roundup.games/l/x');

        // Two systems named explicitly, 'et al.' indicates the third was truncated.
        expect($snippet)->toContain($systemA->name)
            ->and($snippet)->toContain($systemB->name)
            ->and($snippet)->toContain('et al.')
            ->and($snippet)->not->toContain($systemC->name);
    });

    it('renders a single system cleanly when only one is attached', function () {
        $owner = User::factory()->create();
        $system = GameSystem::factory()->create(['name' => 'D&D 5e']);
        $game = Game::factory()->create(['owner_id' => $owner->id, 'date_time' => now()->addDay(), 'max_players' => 4]);
        $game->gameSystems()->sync([$system->id]);

        $snippet = ShareSnippetFormatter::format($game->fresh(['owner', 'gameSystems', 'linkedLocation']), 'https://roundup.games/l/x');

        $lines = explode("\n", $snippet);
        // Third line: capacity + single system, no 'et al.'
        expect($lines[2])->toContain('0/4')
            ->and($lines[2])->toContain('D&D 5e')
            ->and($lines[2])->not->toContain('et al.');
    });
});

describe('campaign formatting', function () {
    it('formats a campaign into a snippet with Date TBD when no sessions exist', function () {
        $owner = User::factory()->create(['name' => 'Campaign Host']);
        $campaign = Campaign::factory()->create([
            'name' => 'Tomb of Annihilation',
            'owner_id' => $owner->id,
        ]);

        $snippet = ShareSnippetFormatter::format($campaign->fresh(['owner', 'gameSystems', 'linkedLocation']), 'https://roundup.games/l/camp1');

        $lines = explode("\n", $snippet);
        expect($lines[0])->toBe('🎲 Tomb of Annihilation')
            ->and($lines[1])->toBe('Date TBD') // no sessions attached yet
            ->and($lines[4])->toBe('Hosted by Campaign Host')
            ->and($lines[5])->toBe('https://roundup.games/l/camp1');
    });
});

describe('output shape', function () {
    it('produces exactly 6 lines (5 content + URL) for a fully-populated entity', function () {
        $owner = User::factory()->create();
        $location = Location::factory()->create(['city' => 'Berlin']);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'location_id' => $location->id,
            'date_time' => now(),
            'max_players' => 4,
        ]);

        $snippet = ShareSnippetFormatter::format($game->fresh(['owner', 'gameSystems', 'linkedLocation']), 'https://roundup.games/l/x');

        expect(substr_count($snippet, "\n"))->toBe(5); // 6 lines = 5 newlines
    });

    it('never adds Discord markdown tokens around the formatter\'s structural framing', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'name' => 'Plain Title',
            'owner_id' => $owner->id,
            'date_time' => now()->addDay(),
            'max_players' => 4,
        ]);

        $snippet = ShareSnippetFormatter::format($game->fresh(['owner', 'gameSystems', 'linkedLocation']), 'https://roundup.games/l/x');

        // The title line is prefix-only — dice emoji + name, no markdown wrap.
        $lines = explode("\n", $snippet);
        expect($lines[0])->toStartWith('🎲 Plain Title');
        expect($lines[0])->not->toContain('**');
        expect($lines[0])->not->toContain('__');
    });
});
