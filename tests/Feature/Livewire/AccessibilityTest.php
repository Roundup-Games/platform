<?php

use App\Enums\ParticipantStatus;
use App\Livewire\Dashboard;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->user = User::factory()->create([
        'profile_complete' => true,
    ]);
    $this->actingAs($this->user);
    URL::defaults(['locale' => 'en']);
});

describe('Dashboard — decorative icons have aria-hidden', function () {
    test('smart prompt icon has aria-hidden attribute', function () {
        $html = Livewire::test(Dashboard::class)
            ->html();

        // All material-symbols-outlined spans should have aria-hidden="true"
        preg_match_all('/<span[^>]*class="material-symbols-outlined[^"]*"[^>]*>/', $html, $matches);
        foreach ($matches[0] as $iconSpan) {
            expect($iconSpan)->toContain('aria-hidden="true"');
        }
    });
});

describe('Dashboard — smart prompt has live region attributes', function () {
    test('smart prompt container has role=status and aria-live=polite', function () {
        $html = Livewire::test(Dashboard::class)
            ->html();

        expect($html)->toContain('role="status"');
        expect($html)->toContain('aria-live="polite"');
    });
});

describe('Dashboard — community feed uses semantic list structure', function () {
    test('feed items are wrapped in ul with role=list', function () {
        // Community Pulse requires established mode (1+ attended game) and 3+ follows
        $gameSystem = GameSystem::factory()->create();
        $attendedGame = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'completed',
            'date_time' => now()->subDays(5),
        ]);
        GameParticipant::factory()->create([
            'game_id' => $attendedGame->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $friends = User::factory()->count(3)->create();
        foreach ($friends as $friend) {
            UserRelationship::create([
                'user_id' => $this->user->id,
                'related_user_id' => $friend->id,
                'type' => 'follow',
            ]);
        }

        Game::factory()->create([
            'owner_id' => $friends[0]->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Cache::flush();
        $html = Livewire::test(Dashboard::class)
            ->html();

        // Feed items list uses ul/li
        expect($html)->toContain('<ul class="space-y-1" role="list">');
        expect($html)->toContain('<li>');
    });

    test('feed timestamps use time element with datetime attribute', function () {
        // Community Pulse requires 3+ follows AND established dashboard mode (1+ attended game)
        $gameSystem = GameSystem::factory()->create();
        $attendedGame = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'completed',
            'date_time' => now()->subDays(5),
        ]);
        GameParticipant::factory()->create([
            'game_id' => $attendedGame->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Community Pulse requires 3+ follows to render
        $friends = User::factory()->count(3)->create();
        foreach ($friends as $friend) {
            UserRelationship::create([
                'user_id' => $this->user->id,
                'related_user_id' => $friend->id,
                'type' => 'follow',
            ]);
        }

        Game::factory()->create([
            'owner_id' => $friends[0]->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Cache::flush();
        $html = Livewire::test(Dashboard::class)
            ->html();

        // Timestamps should use <time> with datetime attribute
        expect($html)->toContain('<time class="text-[10px] text-on-surface-variant flex-shrink-0"');
        expect($html)->toContain('datetime="');
    });
});

describe('Dashboard — quick actions use nav with aria-label', function () {
    test('quick actions container has nav element with aria-label', function () {
        $html = Livewire::test(Dashboard::class)
            ->html();

        // Quick actions should use <nav> with aria-label
        expect($html)->toContain('<nav class="flex flex-wrap gap-3"');
        expect($html)->toContain('aria-label="Quick Actions"');
    });
});

describe('Dashboard — heading hierarchy is correct', function () {
    test('section headings use h3 elements', function () {
        $html = Livewire::test(Dashboard::class)
            ->html();

        // Main section headings should use h3
        expect($html)->toContain('<h3 class="font-heading text-lg font-semibold text-on-surface flex items-center gap-2 mb-4">');
    });
});

describe('Dashboard — skip link targets main content', function () {
    test('layout provides skip link pointing to main-content', function () {
        // Verify the layout blade has the skip link targeting #main-content
        $this->get(route('dashboard'))
            ->assertSee('Skip to content')
            ->assertSee('main-content');
    });
});
