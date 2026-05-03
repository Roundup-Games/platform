<?php

use App\Models\Campaign;
use App\Models\User;
use function Pest\Laravel\{actingAs, get};

// ── Helpers ──────────────────────────────────────────────

function campaignsPageCreateUser(array $overrides = []): User
{
    return User::factory()->create(['profile_complete' => true, ...$overrides]);
}

// ═══════════════════════════════════════════════════════════
// GUEST REDIRECT
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Guest Access', function () {
    it('redirects guests to /discover', function () {
        get('/en/campaigns')
            ->assertRedirect('/en/discover');
    });
});

// ═══════════════════════════════════════════════════════════
// AUTHENTICATED ACCESS
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Authenticated Access', function () {
    it('renders for authenticated users', function () {
        $user = campaignsPageCreateUser();

        actingAs($user)
            ->get('/en/campaigns')
            ->assertOk()
            ->assertSee(__('campaigns.heading_my_campaigns'));
    });
});
