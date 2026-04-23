<?php

use App\Enums\GmProficiency;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;

describe('GmDirectory', function () {
    it('renders the GM directory page for guests', function () {
        Livewire\Livewire::test(App\Livewire\GM\GmDirectory::class)
            ->assertOk()
            ->assertSee('Game Master Directory');
    });

    it('shows active GMs in the directory', function () {
        $gm = GMProfile::factory()->create(['is_active' => true]);
        $gm->user->update(['name' => 'Alice the GM']);

        Livewire\Livewire::test(App\Livewire\GM\GmDirectory::class)
            ->assertSee('Alice the GM');
    });

    it('hides inactive GMs from the directory', function () {
        $gm = GMProfile::factory()->create(['is_active' => false]);
        $gm->user->update(['name' => 'Hidden GM']);

        Livewire\Livewire::test(App\Livewire\GM\GmDirectory::class)
            ->assertDontSee('Hidden GM');
    });

    it('filters by search query (GM name)', function () {
        $gm1 = GMProfile::factory()->create(['is_active' => true]);
        $gm1->user->update(['name' => 'Dragon Master']);

        $gm2 = GMProfile::factory()->create(['is_active' => true]);
        $gm2->user->update(['name' => 'Board Game Host']);

        Livewire\Livewire::test(App\Livewire\GM\GmDirectory::class)
            ->set('search', 'Dragon')
            ->assertSee('Dragon Master')
            ->assertDontSee('Board Game Host');
    });

    it('filters by specialization', function () {
        $gm1 = GMProfile::factory()->create([
            'is_active' => true,
            'specializations' => ['storytelling'],
        ]);
        $gm1->user->update(['name' => 'Storyteller GM']);

        $gm2 = GMProfile::factory()->create([
            'is_active' => true,
            'specializations' => ['rule-of-cool'],
        ]);
        $gm2->user->update(['name' => 'Cool GM']);

        Livewire\Livewire::test(App\Livewire\GM\GmDirectory::class)
            ->set('specialization', 'storytelling')
            ->assertSee('Storyteller GM')
            ->assertDontSee('Cool GM');
    });

    it('filters by game system', function () {
        $system = GameSystem::factory()->create(['name' => 'D&D 5e']);
        $otherSystem = GameSystem::factory()->create(['name' => 'Pathfinder']);

        $gm1 = GMProfile::factory()->create(['is_active' => true]);
        $gm1->user->update(['name' => 'DnD GM']);
        Game::factory()->create([
            'owner_id' => $gm1->user_id,
            'game_system_id' => $system->id,
        ]);

        $gm2 = GMProfile::factory()->create(['is_active' => true]);
        $gm2->user->update(['name' => 'PF GM']);
        Game::factory()->create([
            'owner_id' => $gm2->user_id,
            'game_system_id' => $otherSystem->id,
        ]);

        Livewire\Livewire::test(App\Livewire\GM\GmDirectory::class)
            ->set('game_system_id', $system->id)
            ->assertSee('DnD GM')
            ->assertDontSee('PF GM');
    });

    it('filters by minimum rating', function () {
        $gm1 = GMProfile::factory()->create([
            'is_active' => true,
            'average_rating' => 4.5,
            'review_count' => 10,
        ]);
        $gm1->user->update(['name' => 'Top Rated GM']);

        $gm2 = GMProfile::factory()->create([
            'is_active' => true,
            'average_rating' => 2.0,
            'review_count' => 3,
        ]);
        $gm2->user->update(['name' => 'Low Rated GM']);

        Livewire\Livewire::test(App\Livewire\GM\GmDirectory::class)
            ->set('min_rating', 4)
            ->assertSee('Top Rated GM')
            ->assertDontSee('Low Rated GM');
    });

    it('sorts by highest rated', function () {
        $gm1 = GMProfile::factory()->create([
            'is_active' => true,
            'average_rating' => 5.0,
        ]);
        $gm1->user->update(['name' => 'Five Star']);

        $gm2 = GMProfile::factory()->create([
            'is_active' => true,
            'average_rating' => 3.0,
        ]);
        $gm2->user->update(['name' => 'Three Star']);

        $component = Livewire\Livewire::test(App\Livewire\GM\GmDirectory::class)
            ->set('sortBy', 'highest_rated');

        $names = $component->viewData('results')->pluck('user.name')->toArray();
        expect($names[0])->toBe('Five Star');
    });

    it('sorts by most reviewed', function () {
        $gm1 = GMProfile::factory()->create([
            'is_active' => true,
            'review_count' => 50,
        ]);
        $gm1->user->update(['name' => 'Popular GM']);

        $gm2 = GMProfile::factory()->create([
            'is_active' => true,
            'review_count' => 2,
        ]);
        $gm2->user->update(['name' => 'New GM']);

        $component = Livewire\Livewire::test(App\Livewire\GM\GmDirectory::class)
            ->set('sortBy', 'most_reviewed');

        $names = $component->viewData('results')->pluck('user.name')->toArray();
        expect($names[0])->toBe('Popular GM');
    });

    it('sorts by newest', function () {
        $gm1 = GMProfile::factory()->create([
            'is_active' => true,
            'created_at' => now()->subDays(10),
        ]);
        $gm1->user->update(['name' => 'Older GM']);

        $gm2 = GMProfile::factory()->create([
            'is_active' => true,
            'created_at' => now(),
        ]);
        $gm2->user->update(['name' => 'Newer GM']);

        $component = Livewire\Livewire::test(App\Livewire\GM\GmDirectory::class)
            ->set('sortBy', 'newest');

        $names = $component->viewData('results')->pluck('user.name')->toArray();
        expect($names[0])->toBe('Newer GM');
    });

    it('clears all filters', function () {
        $component = Livewire\Livewire::test(App\Livewire\GM\GmDirectory::class)
            ->set('search', 'test')
            ->set('specialization', 'storytelling')
            ->set('min_rating', 4)
            ->call('clearFilters');

        $component
            ->assertSet('search', '')
            ->assertSet('specialization', null)
            ->assertSet('min_rating', null)
            ->assertSet('sortBy', 'highest_rated');
    });

    it('detects active filters correctly', function () {
        $component = Livewire\Livewire::test(App\Livewire\GM\GmDirectory::class);
        expect($component->instance()->hasActiveFilters())->toBeFalse();

        $component->set('search', 'test');
        expect($component->instance()->hasActiveFilters())->toBeTrue();
    });

    it('shows star rating badge for rated GMs', function () {
        $gm = GMProfile::factory()->create([
            'is_active' => true,
            'average_rating' => 4.75,
            'review_count' => 12,
        ]);
        $gm->user->update(['name' => 'Rated GM']);

        Livewire\Livewire::test(App\Livewire\GM\GmDirectory::class)
            ->assertSee('4.8')
            ->assertSee('12');
    });

    it('shows empty state when no GMs match filters', function () {
        Livewire\Livewire::test(App\Livewire\GM\GmDirectory::class)
            ->set('search', 'nonexistent_gm_xyz')
            ->assertSee('No Game Masters found');
    });

    it('paginates at 12 per page', function () {
        GMProfile::factory()->count(15)->create(['is_active' => true]);

        $component = Livewire\Livewire::test(App\Livewire\GM\GmDirectory::class);
        $results = $component->viewData('results');

        expect($results->count())->toBe(12);
        expect($results->total())->toBe(15);
    });

    it('shows proficiency badges from reviews on GM cards', function () {
        $gm = GMProfile::factory()->create([
            'is_active' => true,
            'specializations' => [],
        ]);
        $gm->user->update(['name' => 'Badged GM']);

        Review::factory()->create([
            'gm_profile_id' => $gm->id,
            'proficiency_tags' => ['storytelling', 'voices'],
            'status' => 'published',
        ]);

        Livewire\Livewire::test(App\Livewire\GM\GmDirectory::class)
            ->assertSee('Badged GM')
            ->assertSee('Storyteller')
            ->assertSee('Character Voices');
    });
});
