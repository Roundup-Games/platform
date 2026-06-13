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
    it('redirects guests to /discover', function () {
        get('/en/campaigns')
            ->assertRedirect('/en/discover');
    });
});
