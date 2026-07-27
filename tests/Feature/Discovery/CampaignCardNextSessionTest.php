<?php

use App\Dto\DiscoveryFilters;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\DiscoveryQueryService;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

// ── Helpers ──────────────────────────────────────────────

function createPublicCampaign(User $owner, GameSystem $system, array $overrides = []): Campaign
{
    return Campaign::factory()->create(array_merge([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => ['en' => 'Test Campaign'],
        'description' => ['en' => 'Test description'],
        'visibility' => 'public',
        'status' => 'active',
        'language' => 'en',
        'recurrence' => 'weekly',
        'session_duration' => 3,
    ], $overrides));
}

function renderCampaignCard(Campaign $campaign): string
{
    // Eager-load the next upcoming session just as DiscoveryQueryService does
    $campaign->load(['sessions' => fn ($q) => $q->where('status', 'scheduled')->where('date_time', '>', now())->orderBy('date_time')->limit(1)]);

    return view('livewire.discovery.partials.campaign-card', ['campaign' => $campaign])->render();
}

// ═══════════════════════════════════════════════════════════
// NEXT SESSION DISPLAY
// ═══════════════════════════════════════════════════════════

describe('Campaign card next session display', function () {
    test('campaign card shows next session date/time when upcoming session exists', function () {
        $campaign = createPublicCampaign($this->owner, $this->gameSystem);

        $session = Game::factory()->create([
            'campaign_id' => $campaign->id,
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(3)->setHour(19)->setMinute(0),
        ]);

        $rendered = renderCampaignCard($campaign->fresh());

        // Should contain the formatted date/time of the next session
        expect($rendered)->toContain('calendar_today');
        expect($rendered)->toContain($session->date_time->format('M')); // Month abbreviation
    });

    test('campaign card falls back to recurrence when no upcoming sessions', function () {
        $campaign = createPublicCampaign($this->owner, $this->gameSystem, [
            'recurrence' => 'weekly',
            'session_duration' => 3,
        ]);

        // No sessions created
        $rendered = renderCampaignCard($campaign);

        // Should show recurrence info
        expect($rendered)->toContain('repeat');
        expect($rendered)->toContain('Weekly');
        // And session duration
        expect($rendered)->toContain('3h');
        expect($rendered)->toContain('per session');
        // Should NOT show calendar_today icon (that's for specific session dates)
        expect($rendered)->not->toContain('calendar_today');
    });

    test('campaign card does not show duration when next session is present', function () {
        $campaign = createPublicCampaign($this->owner, $this->gameSystem, [
            'session_duration' => 3,
        ]);

        Game::factory()->create([
            'campaign_id' => $campaign->id,
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(5),
        ]);

        $rendered = renderCampaignCard($campaign->fresh());

        // Should show next session date, not the recurrence/duration fallback
        expect($rendered)->toContain('calendar_today');
        expect($rendered)->not->toContain('per session');
    });

    test('campaign card shows recurrence when session is in the past', function () {
        $campaign = createPublicCampaign($this->owner, $this->gameSystem, [
            'recurrence' => 'bi-weekly',
        ]);

        // Past session
        Game::factory()->create([
            'campaign_id' => $campaign->id,
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'date_time' => now()->subDays(3),
        ]);

        $rendered = renderCampaignCard($campaign->fresh());

        // Should show recurrence since no upcoming session
        expect($rendered)->toContain('repeat');
        expect($rendered)->toContain('Bi-weekly');
    });
});

// ═══════════════════════════════════════════════════════════
// SORT KEY INTEGRATION
// ═══════════════════════════════════════════════════════════

describe('Campaign sort key with next session', function () {
    test('campaign with upcoming session uses session date as sort key', function () {
        $service = app(DiscoveryQueryService::class);

        $campaign = createPublicCampaign($this->owner, $this->gameSystem);

        $sessionDateTime = now()->addDays(7);
        Game::factory()->create([
            'campaign_id' => $campaign->id,
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'date_time' => $sessionDateTime,
        ]);

        // Drive the REAL sort-key computation via getMergedResults() — the
        // method that decorates campaigns with discoverable_sort_key in
        // production. The previous version called buildCampaignsQuery() (which
        // returns raw campaigns without the sort key) and then manually
        // replicated the service's sort-key expression via ->each(), asserting
        // the value it had just computed (a tautology).
        $result = $service->getMergedResults(new DiscoveryFilters, $this->owner, 0, null, null, false, null, null);
        $found = $result['results']->first(fn ($c) => $c->id === $campaign->id);

        expect($found)->not->toBeNull();
        expect($found->discoverable_type)->toBe('campaign');
        expect($found->discoverable_sort_key)->toBe($sessionDateTime->timestamp);
    });

    test('campaign without upcoming session uses created_at as sort key', function () {
        $service = app(DiscoveryQueryService::class);

        $campaign = createPublicCampaign($this->owner, $this->gameSystem);
        // No sessions

        $result = $service->getMergedResults(new DiscoveryFilters, $this->owner, 0, null, null, false, null, null);
        $found = $result['results']->first(fn ($c) => $c->id === $campaign->id);

        expect($found)->not->toBeNull();
        expect($found->discoverable_type)->toBe('campaign');
        expect($found->discoverable_sort_key)->toBe($campaign->created_at->timestamp);
    });
});
