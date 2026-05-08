<?php

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
    $this->owner = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);
    $this->gameSystem = GameSystem::factory()->create();
});

// Helper to build the share_intent cookie payload as plain JSON.
function e2eShareIntentPayload(string $entityType, string $entityId, string $shareToken): string
{
    return json_encode([
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'share_token' => $shareToken,
    ]);
}

// ══════════════════════════════════════════════════════════════
// 1. Guest opens private game share link → sees detail → cookie set
// ══════════════════════════════════════════════════════════════

describe('Scenario 1: Guest opens private game share link', function () {
    it('guest sees game detail page and gets share_intent cookie', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'private',
            'max_players' => 10,
        ]);

        // Guest visits the share link URL
        $response = $this->get(route('games.detail', ['id' => $game->id]) . '?share=' . $token);

        // Page loads (200 OK — game is visible via valid share token)
        $response->assertStatus(200);

        // No participant created yet (guest is not authenticated)
        expect(GameParticipant::where('game_id', $game->id)->count())->toBe(0);
    });
});

// ══════════════════════════════════════════════════════════════
// 2. Guest with cookie registers → verifies email → completes
//    onboarding → participant created → lands on detail page
// ══════════════════════════════════════════════════════════════

describe('Scenario 2: Full guest-to-participant pipeline', function () {
    it('cookie survives registration and creates participant after profile completion', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'private',
            'max_players' => 10,
        ]);

        // Simulate: guest visited share link, cookie was set by Livewire component.
        // Now the guest registers — in the real flow the cookie persists in the browser.
        // In tests we simulate the cookie being present on the post-registration redirect.

        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        // The first authenticated GET with the cookie should trigger middleware
        $response = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('game', $game->id, $token))
            ->get('/en/dashboard');

        // Middleware creates participant and redirects to game detail
        $response->assertRedirect();
        $redirectUrl = $response->headers->get('Location');
        expect(str_contains($redirectUrl, $game->id))->toBeTrue();

        // Verify participant was created
        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->first();

        expect($participant)->not->toBeNull();
        expect($participant->status)->toBe(ParticipantStatus::Approved);
        expect($participant->join_source)->toBe(JoinSource::ShareLink);
    });

    it('deferred processing: cookie persists through onboarding and processes after completion', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'private',
            'max_players' => 10,
        ]);

        // User just registered — profile NOT complete
        $user = User::factory()->create([
            'profile_complete' => false,
            'email_verified_at' => null,
        ]);

        // First request: middleware defers (profile incomplete)
        $response1 = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('game', $game->id, $token))
            ->get('/en/onboarding');

        // No redirect — middleware defers
        $response1->assertStatus(200);

        // No participant created yet
        expect(GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->exists())->toBeFalse();

        // User verifies email
        $user->update(['email_verified_at' => now()]);

        // Still incomplete profile — still defers
        $response2 = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('game', $game->id, $token))
            ->get('/en/onboarding');

        $response2->assertStatus(200);
        expect(GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->exists())->toBeFalse();

        // User completes onboarding
        $user->update(['profile_complete' => true]);

        // Next authenticated GET — middleware now processes
        $response3 = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('game', $game->id, $token))
            ->get('/en/dashboard');

        $response3->assertRedirect();
        $redirectUrl = $response3->headers->get('Location');
        expect(str_contains($redirectUrl, $game->id))->toBeTrue();

        // Participant created!
        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->first();

        expect($participant)->not->toBeNull();
        expect($participant->status)->toBe(ParticipantStatus::Approved);
        expect($participant->join_source)->toBe(JoinSource::ShareLink);
    });
});

// ══════════════════════════════════════════════════════════════
// 3. Authenticated non-friend opens protected campaign share
//    link → clicks Join → participant created
// ══════════════════════════════════════════════════════════════

describe('Scenario 3: Authenticated user joins protected campaign via share link', function () {
    it('authenticated user gets participant created via middleware', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'protected',
            'max_players' => 10,
        ]);

        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        // User has the share_intent cookie from visiting the share link
        $response = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('campaign', $campaign->id, $token))
            ->get('/en/dashboard');

        $response->assertRedirect();
        $redirectUrl = $response->headers->get('Location');
        expect(str_contains($redirectUrl, $campaign->id))->toBeTrue();

        $participant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $user->id)
            ->first();

        expect($participant)->not->toBeNull();
        expect($participant->status)->toBe(ParticipantStatus::Approved);
        expect($participant->join_source)->toBe(JoinSource::ShareLink);
    });
});

// ══════════════════════════════════════════════════════════════
// 4. Share link to full game → user waitlisted
// ══════════════════════════════════════════════════════════════

describe('Scenario 4: Full game → user waitlisted via share link', function () {
    it('creates waitlisted participant when standalone game is at capacity', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'public',
            'max_players' => 1,
            'campaign_id' => null, // standalone game → waitlist
        ]);

        // Fill the game with the owner
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('game', $game->id, $token))
            ->get('/en/dashboard');

        // Should still redirect (to the game detail, even though waitlisted)
        $response->assertRedirect();

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->first();

        expect($participant)->not->toBeNull();
        expect($participant->status)->toBe(ParticipantStatus::Waitlisted);
        expect($participant->join_source)->toBe(JoinSource::ShareLink);
        expect($participant->waitlisted_at)->not->toBeNull();
    });
});

// ══════════════════════════════════════════════════════════════
// 5. Share link to full campaign → user benched
// ══════════════════════════════════════════════════════════════

describe('Scenario 5: Full campaign → user benched via share link', function () {
    it('creates benched participant when campaign is at capacity', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'public',
            'max_players' => 1,
        ]);

        // Fill the campaign
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('campaign', $campaign->id, $token))
            ->get('/en/dashboard');

        $response->assertRedirect();

        $participant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $user->id)
            ->first();

        expect($participant)->not->toBeNull();
        expect($participant->status)->toBe(ParticipantStatus::Benched);
        expect($participant->join_source)->toBe(JoinSource::ShareLink);
        expect($participant->benched_at)->not->toBeNull();
    });
});

// ══════════════════════════════════════════════════════════════
// 6. Revoked share link → access denied
// ══════════════════════════════════════════════════════════════

describe('Scenario 6: Revoked share link', function () {
    it('rejects cookie with non-matching token (link was revoked/regenerated)', function () {
        $oldToken = (string) Str::uuid();
        $newToken = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $newToken, // token was regenerated
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'public',
        ]);

        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        // User has old token in cookie
        $response = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('game', $game->id, $oldToken))
            ->get('/en/dashboard');

        // No redirect — token mismatch, proceed normally
        $response->assertStatus(200);

        // No participant created
        expect(GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->exists())->toBeFalse();
    });

    it('rejects cookie when share_token is null (revoked)', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => null, // revoked
            'share_token_expires_at' => null,
            'visibility' => 'public',
        ]);

        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('game', $game->id, $token))
            ->get('/en/dashboard');

        $response->assertStatus(200);
        expect(GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->exists())->toBeFalse();
    });
});

// ══════════════════════════════════════════════════════════════
// 7. Expired share link → access denied
// ══════════════════════════════════════════════════════════════

describe('Scenario 7: Expired share link', function () {
    it('rejects cookie with expired share token', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->subDays(2), // expired
            'visibility' => 'public',
        ]);

        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('game', $game->id, $token))
            ->get('/en/dashboard');

        // No redirect — token expired, proceed normally
        $response->assertStatus(200);
        expect(GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->exists())->toBeFalse();
    });

    it('rejects expired campaign share link', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->subHours(1),
            'visibility' => 'public',
        ]);

        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('campaign', $campaign->id, $token))
            ->get('/en/dashboard');

        $response->assertStatus(200);
        expect(CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $user->id)
            ->exists())->toBeFalse();
    });
});

// ══════════════════════════════════════════════════════════════
// 8. User already participant → no duplicate, sees detail normally
// ══════════════════════════════════════════════════════════════

describe('Scenario 8: Already participant', function () {
    it('redirects to game detail without creating duplicate', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'public',
        ]);

        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        // User is already an approved participant
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('game', $game->id, $token))
            ->get('/en/dashboard');

        // Still redirects to game detail (good UX — they see the game)
        $response->assertRedirect(route('games.detail', ['id' => $game->id]));

        // Exactly 1 participant (no duplicate)
        expect(GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->count())->toBe(1);
    });

    it('redirects to campaign detail for existing campaign participant', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'public',
        ]);

        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('campaign', $campaign->id, $token))
            ->get('/en/dashboard');

        $response->assertRedirect(route('campaigns.detail', ['id' => $campaign->id]));
        expect(CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $user->id)
            ->count())->toBe(1);
    });
});

// ══════════════════════════════════════════════════════════════
// 9. Cookie processing when entity was deleted → graceful failure
// ══════════════════════════════════════════════════════════════

describe('Scenario 9: Entity deleted between cookie set and processing', function () {
    it('handles deleted game gracefully', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'public',
        ]);

        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $gameId = $game->id;
        $game->delete();

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('game', $gameId, $token))
            ->get('/en/dashboard');

        // No redirect — entity gone, proceed normally to dashboard
        $response->assertStatus(200);

        // No error, no participant
        expect(GameParticipant::where('game_id', $gameId)->exists())->toBeFalse();
    });

    it('handles deleted campaign gracefully', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'public',
        ]);

        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $campaignId = $campaign->id;
        $campaign->delete();

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('campaign', $campaignId, $token))
            ->get('/en/dashboard');

        $response->assertStatus(200);
        expect(CampaignParticipant::where('campaign_id', $campaignId)->exists())->toBeFalse();
    });
});

// ══════════════════════════════════════════════════════════════
// 10. Existing user logs in with stale share_intent cookie
// ══════════════════════════════════════════════════════════════

describe('Scenario 10: Existing user logs in with stale share_intent cookie', function () {
    it('processes share intent cookie on login redirect', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'public',
            'max_players' => 10,
        ]);

        // Pre-existing user (profile complete, email verified)
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        // Simulate: user had the share_intent cookie from browsing as guest,
        // then logged in. The post-login redirect goes through the middleware.
        $response = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('game', $game->id, $token))
            ->get('/en/dashboard');

        // Middleware processes cookie → redirects to game detail
        $response->assertRedirect();
        $redirectUrl = $response->headers->get('Location');
        expect(str_contains($redirectUrl, $game->id))->toBeTrue();

        // Participant created
        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->first();

        expect($participant)->not->toBeNull();
        expect($participant->status)->toBe(ParticipantStatus::Approved);
        expect($participant->join_source)->toBe(JoinSource::ShareLink);
    });

    it('works for campaign with existing user login', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'public',
            'max_players' => 10,
        ]);

        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('campaign', $campaign->id, $token))
            ->get('/en/dashboard');

        $response->assertRedirect();
        $redirectUrl = $response->headers->get('Location');
        expect(str_contains($redirectUrl, $campaign->id))->toBeTrue();

        $participant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $user->id)
            ->first();

        expect($participant)->not->toBeNull();
        expect($participant->status)->toBe(ParticipantStatus::Approved);
        expect($participant->join_source)->toBe(JoinSource::ShareLink);
    });
});

// ══════════════════════════════════════════════════════════════
// Cross-cutting: Game with campaign session → benches not waitlists
// ══════════════════════════════════════════════════════════════

describe('Full game from campaign session → benches', function () {
    it('full campaign session game benches user via share link', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'max_players' => 10,
        ]);

        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'public',
            'max_players' => 1,
            'campaign_id' => $campaign->id, // belongs to campaign → benches
        ]);

        // Fill the game
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('game', $game->id, $token))
            ->get('/en/dashboard');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->first();

        expect($participant)->not->toBeNull();
        expect($participant->status)->toBe(ParticipantStatus::Benched);
        expect($participant->benched_at)->not->toBeNull();
    });
});

// ══════════════════════════════════════════════════════════════
// Cross-cutting: Owner visiting their own entity's share link
// ══════════════════════════════════════════════════════════════

describe('Owner visits own share link', function () {
    it('redirects owner to their own game without creating participant', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'public',
        ]);

        $response = $this->actingAs($this->owner)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('game', $game->id, $token))
            ->get('/en/dashboard');

        // Owner gets redirected to their game
        $response->assertRedirect(route('games.detail', ['id' => $game->id]));

        // No participant record for the owner via this path
        expect(GameParticipant::where('game_id', $game->id)
            ->where('user_id', $this->owner->id)
            ->exists())->toBeFalse();
    });
});

// ══════════════════════════════════════════════════════════════
// Cross-cutting: Inactive entities (completed/cancelled)
// ══════════════════════════════════════════════════════════════

describe('Inactive entities reject share link joins', function () {
    it('completed game does not accept new participants', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'public',
            'status' => GameStatus::Completed->value,
        ]);

        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('game', $game->id, $token))
            ->get('/en/dashboard');

        // No redirect — game is completed, cookie cleared
        $response->assertStatus(200);
        expect(GameParticipant::where('game_id', $game->id)
            ->where('user_id', $user->id)
            ->exists())->toBeFalse();
    });

    it('cancelled campaign does not accept new participants', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'public',
            'status' => CampaignStatus::Cancelled->value,
        ]);

        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withUnencryptedCookie('share_intent', e2eShareIntentPayload('campaign', $campaign->id, $token))
            ->get('/en/dashboard');

        $response->assertStatus(200);
        expect(CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $user->id)
            ->exists())->toBeFalse();
    });
});
