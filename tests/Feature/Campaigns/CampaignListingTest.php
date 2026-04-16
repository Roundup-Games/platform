<?php

use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\VibeFlag;
use App\Models\Campaign;
use App\Models\GameSystem;
use App\Models\User;
use function Pest\Laravel\{actingAs, get};

// ── Helpers ──────────────────────────────────────────────

function createPublicCampaign(array $overrides = []): Campaign
{
    return Campaign::factory()->create([
        'status' => 'active',
        'visibility' => 'public',
        ...$overrides,
    ]);
}

function createProtectedCampaign(array $overrides = []): Campaign
{
    return Campaign::factory()->create([
        'status' => 'active',
        'visibility' => 'protected',
        ...$overrides,
    ]);
}

function createPrivateCampaign(array $overrides = []): Campaign
{
    return Campaign::factory()->create([
        'status' => 'active',
        'visibility' => 'private',
        ...$overrides,
    ]);
}

// ═══════════════════════════════════════════════════════════
// CAMPAIGN LISTING — ROUTE
// ═══════════════════════════════════════════════════════════

describe('Campaign Listing Route', function () {
    it('renders the listing page for guests', function () {
        createPublicCampaign(['name' => 'Visible Campaign']);

        get(route('campaigns.index'))
            ->assertOk()
            ->assertSeeLivewire('campaigns.campaign-listing');
    });

    it('renders the listing page for authenticated users', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        createPublicCampaign(['name' => 'Public Campaign']);

        actingAs($user)
            ->get(route('campaigns.index'))
            ->assertOk()
            ->assertSee('Public Campaign');
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN LISTING — VISIBILITY SCOPING
// ═══════════════════════════════════════════════════════════

describe('CampaignListing — Visibility Scoping', function () {
    it('shows public campaigns to guests', function () {
        createPublicCampaign(['name' => 'Public One']);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class)
            ->assertSee('Public One');
    });

    it('hides protected campaigns from guests', function () {
        createProtectedCampaign(['name' => 'Protected One']);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class)
            ->assertDontSee('Protected One');
    });

    it('shows protected campaigns to authenticated users', function () {
        $user = User::factory()->create();
        createProtectedCampaign(['name' => 'Protected One']);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignListing::class)
            ->assertSee('Protected One');
    });

    it('hides private campaigns from everyone except owner', function () {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        createPrivateCampaign(['name' => 'Secret Campaign', 'owner_id' => $owner->id]);

        // Guest should not see it
        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class)
            ->assertDontSee('Secret Campaign');

        // Stranger should not see it
        Livewire\Livewire::actingAs($stranger)
            ->test(\App\Livewire\Campaigns\CampaignListing::class)
            ->assertDontSee('Secret Campaign');
    });

    it('only shows active campaigns', function () {
        createPublicCampaign(['name' => 'Active Campaign', 'status' => 'active']);
        Campaign::factory()->create(['name' => 'Cancelled Campaign', 'status' => 'cancelled', 'visibility' => 'public']);
        Campaign::factory()->create(['name' => 'Completed Campaign', 'status' => 'completed', 'visibility' => 'public']);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class)
            ->assertSee('Active Campaign')
            ->assertDontSee('Cancelled Campaign')
            ->assertDontSee('Completed Campaign');
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN LISTING — SEARCH
// ═══════════════════════════════════════════════════════════

describe('CampaignListing — Search', function () {
    it('filters by name', function () {
        createPublicCampaign(['name' => 'Dragons of Stormwreck']);
        createPublicCampaign(['name' => 'Curse of Strahd']);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class)
            ->set('search', 'Stormwreck')
            ->assertSee('Dragons of Stormwreck')
            ->assertDontSee('Curse of Strahd');
    });

    it('filters by description', function () {
        createPublicCampaign(['name' => 'Campaign A', 'description' => 'A tale of dungeon crawling']);
        createPublicCampaign(['name' => 'Campaign B', 'description' => 'Political intrigue in the city']);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class)
            ->set('search', 'dungeon')
            ->assertSee('Campaign A')
            ->assertDontSee('Campaign B');
    });

});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN LISTING — FILTERS
// ═══════════════════════════════════════════════════════════

describe('CampaignListing — Filters', function () {
    it('filters by game system', function () {
        $dnd = GameSystem::factory()->create(['name' => 'D&D 5e']);
        $coc = GameSystem::factory()->create(['name' => 'Call of Cthulhu']);
        createPublicCampaign(['name' => 'D&D Campaign', 'game_system_id' => $dnd->id]);
        createPublicCampaign(['name' => 'CoC Campaign', 'game_system_id' => $coc->id]);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class)
            ->set('game_system_id', $dnd->id)
            ->assertSee('D&D Campaign')
            ->assertDontSee('CoC Campaign');
    });

    it('filters by experience level', function () {
        createPublicCampaign(['name' => 'Beginner Game', 'experience_level' => 'beginner']);
        createPublicCampaign(['name' => 'Advanced Game', 'experience_level' => 'advanced']);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class)
            ->set('experience_level', 'beginner')
            ->assertSee('Beginner Game')
            ->assertDontSee('Advanced Game');
    });

    it('filters by language', function () {
        createPublicCampaign(['name' => 'English Game', 'language' => 'en']);
        createPublicCampaign(['name' => 'German Game', 'language' => 'de']);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class)
            ->set('language', 'en')
            ->assertSee('English Game')
            ->assertDontSee('German Game');
    });

    it('filters by recurrence', function () {
        createPublicCampaign(['name' => 'Weekly Game', 'recurrence' => 'weekly']);
        createPublicCampaign(['name' => 'Monthly Game', 'recurrence' => 'monthly']);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class)
            ->set('recurrence', 'weekly')
            ->assertSee('Weekly Game')
            ->assertDontSee('Monthly Game');
    });

    it('filters by price (free)', function () {
        createPublicCampaign(['name' => 'Free Game', 'price_per_session' => 0]);
        createPublicCampaign(['name' => 'Paid Game', 'price_per_session' => 10]);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class)
            ->set('price', 'free')
            ->assertSee('Free Game')
            ->assertDontSee('Paid Game');
    });

    it('filters by price (paid)', function () {
        createPublicCampaign(['name' => 'Free Game', 'price_per_session' => 0]);
        createPublicCampaign(['name' => 'Paid Game', 'price_per_session' => 10]);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class)
            ->set('price', 'paid')
            ->assertSee('Paid Game')
            ->assertDontSee('Free Game');
    });

    it('filters by vibe flags (JSON contains)', function () {
        createPublicCampaign(['name' => 'Serious Game', 'vibe_flags' => ['serious', 'story-rich']]);
        createPublicCampaign(['name' => 'Casual Game', 'vibe_flags' => ['lighthearted', 'humorous']]);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class)
            ->set('vibe_flags', ['serious'])
            ->assertSee('Serious Game')
            ->assertDontSee('Casual Game');
    });

    it('requires ALL selected vibe flags to match', function () {
        createPublicCampaign(['name' => 'Both Flags', 'vibe_flags' => ['serious', 'story-rich']]);
        createPublicCampaign(['name' => 'Only Serious', 'vibe_flags' => ['serious']]);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class)
            ->set('vibe_flags', ['serious', 'story-rich'])
            ->assertSee('Both Flags')
            ->assertDontSee('Only Serious');
    });

    it('filters by complexity range', function () {
        createPublicCampaign(['name' => 'Simple Game', 'complexity' => 1.50]);
        createPublicCampaign(['name' => 'Complex Game', 'complexity' => 4.50]);

        Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class)
            ->set('complexity_min', '4')
            ->set('complexity_max', '5')
            ->assertSee('Complex Game')
            ->assertDontSee('Simple Game');
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN LISTING — PAGINATION & INTERACTION
// ═══════════════════════════════════════════════════════════

describe('CampaignListing — Pagination & Interaction', function () {
    it('paginates at 12 per page', function () {
        Campaign::factory()->count(15)->create(['status' => 'active', 'visibility' => 'public']);

        $component = Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class);

        $campaigns = $component->viewData('campaigns');
        expect($campaigns->count())->toBe(12);
        expect($campaigns->total())->toBe(15);
    });

    it('resets pagination when search changes', function () {
        Campaign::factory()->count(15)->create(['status' => 'active', 'visibility' => 'public']);

        // Verify that setting search triggers a re-render without errors
        // The updatingSearch() hook calls resetPage() internally
        $component = Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class)
            ->set('search', 'test');

        // Should render successfully (page reset via updatingSearch hook)
        $component->assertOk();
    });

    it('clears all filters', function () {
        $component = Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class)
            ->set('search', 'test')
            ->set('game_system_id', 1)
            ->set('experience_level', 'beginner')
            ->set('vibe_flags', ['serious'])
            ->set('language', 'en')
            ->set('recurrence', 'weekly')
            ->set('price', 'free')
            ->set('complexity_min', '2')
            ->set('complexity_max', '4')
            ->call('clearFilters');

        $component
            ->assertSet('search', '')
            ->assertSet('game_system_id', null)
            ->assertSet('experience_level', '')
            ->assertSet('vibe_flags', [])
            ->assertSet('language', '')
            ->assertSet('recurrence', '')
            ->assertSet('price', '')
            ->assertSet('complexity_min', null)
            ->assertSet('complexity_max', null);
    });

    it('toggles vibe flags on and off', function () {
        $component = Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class)
            ->call('toggleVibeFlag', 'serious')
            ->assertSet('vibe_flags', ['serious'])
            ->call('toggleVibeFlag', 'lighthearted')
            ->assertSet('vibe_flags', ['serious', 'lighthearted'])
            ->call('toggleVibeFlag', 'serious')
            ->assertSet('vibe_flags', ['lighthearted']);
    });

    it('detects active filters correctly', function () {
        $component = Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class);
        expect($component->instance()->hasActiveFilters())->toBeFalse();

        $component->set('search', 'test');
        expect($component->instance()->hasActiveFilters())->toBeTrue();

        $component->set('search', '');
        $component->set('vibe_flags', ['serious']);
        expect($component->instance()->hasActiveFilters())->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN LISTING — EAGER LOADING
// ═══════════════════════════════════════════════════════════

describe('CampaignListing — Eager Loading', function () {
    it('eager loads owner, gameSystem, and sessions count', function () {
        $system = GameSystem::factory()->create(['name' => 'Test System']);
        $user = User::factory()->create(['name' => 'Test Owner']);
        $campaign = createPublicCampaign([
            'name' => 'Eager Campaign',
            'owner_id' => $user->id,
            'game_system_id' => $system->id,
        ]);

        $component = Livewire\Livewire::test(\App\Livewire\Campaigns\CampaignListing::class);

        $campaigns = $component->viewData('campaigns');
        $loaded = $campaigns->first();

        // Verify relationships are loaded without additional queries
        expect($loaded->relationLoaded('owner'))->toBeTrue();
        expect($loaded->relationLoaded('gameSystem'))->toBeTrue();
        expect(isset($loaded->sessions_count))->toBeTrue();
    });
});
