<?php

use App\Livewire\Campaigns\CampaignDetail;
use App\Livewire\Events\EventDetail;
use App\Livewire\Games\GameDetail;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\URL;

describe('Language mismatch banner on detail pages', function () {

    it('shows banner on game detail when language mismatches', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => GameSystem::factory(),
            'language' => 'de',
            'visibility' => 'public',
        ]);

        URL::defaults(['locale' => 'en']);
        app()->setLocale('en');

        $html = Livewire\Livewire::test(GameDetail::class, ['id' => $game->id])
            ->html();

        // Banner text with language label
        expect($html)->toContain('German');
        // Alpine dismiss logic
        expect($html)->toContain('x-data');
        expect($html)->toContain('x-show');
        // Alert role
        expect($html)->toContain('role="alert"');
        // Translate icon
        expect($html)->toContain('translate');
    });

    it('hides banner on game detail when language matches', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => GameSystem::factory(),
            'language' => 'en',
            'visibility' => 'public',
        ]);

        URL::defaults(['locale' => 'en']);
        app()->setLocale('en');

        $html = Livewire\Livewire::test(GameDetail::class, ['id' => $game->id])
            ->html();

        expect($html)->not->toContain('role="alert"');
        expect($html)->not->toContain('content_language_mismatch_banner');
    });

    it('shows banner on campaign detail when language mismatches', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => GameSystem::factory(),
            'language' => 'de',
            'visibility' => 'public',
        ]);

        URL::defaults(['locale' => 'en']);
        app()->setLocale('en');

        $html = Livewire\Livewire::test(CampaignDetail::class, ['id' => $campaign->id])
            ->html();

        expect($html)->toContain('German');
        expect($html)->toContain('role="alert"');
    });

    it('shows banner on event detail when language mismatches', function () {
        $organizer = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'content_language' => 'de',
            'is_public' => true,
        ]);

        URL::defaults(['locale' => 'en']);
        app()->setLocale('en');

        $html = Livewire\Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->html();

        expect($html)->toContain('German');
        expect($html)->toContain('role="alert"');
    });

    it('includes Alpine dismiss logic on banner', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => GameSystem::factory(),
            'language' => 'de',
            'visibility' => 'public',
        ]);

        URL::defaults(['locale' => 'en']);
        app()->setLocale('en');

        $html = Livewire\Livewire::test(GameDetail::class, ['id' => $game->id])
            ->html();

        expect($html)->toContain('x-data="{ visible: true }"');
        expect($html)->toContain('x-show="visible"');
        expect($html)->toContain('x-on:click="visible = false"');
    });
});
