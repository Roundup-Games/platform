<?php

use App\Models\User;

use function Pest\Laravel\get;

// ── Helpers ──────────────────────────────────────────────

function campaignsPageCreateUser(array $overrides = []): User
{
    return User::factory()->create(['profile_complete' => true, ...$overrides]);
}

// ═══════════════════════════════════════════════════════════
// GUEST REDIRECT
// ═══════════════════════════════════════════════════════════

describe('CampaignsPage — Guest Access', function () {
    it('permanently redirects guests from /campaigns to /dashboard/campaigns', function () {
        get('/en/campaigns')
            ->assertStatus(301)->assertRedirect('/en/dashboard/campaigns');
    });
});
