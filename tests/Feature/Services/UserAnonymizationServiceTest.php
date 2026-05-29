<?php

use App\Enums\ActivityType;
use App\Enums\ParticipantStatus;
use App\Jobs\DeletePostHogUserData;
use App\Models\ActivityLog;
use App\Models\AttendanceReport;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\GmSocialLink;
use App\Models\LinkedAccount;
use App\Models\LocalSubscription;
use App\Models\MembershipType;
use App\Models\NearbyDiscoveryView;
use App\Models\PushSubscription;
use App\Models\Review;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserAppVisit;
use App\Services\PostHogConsentChecker;
use App\Services\UserAnonymizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Traits\CreatesGameInstances;
use Tests\Traits\CreatesTeams;
use Tests\Traits\CreatesUsers;

uses(CreatesUsers::class, CreatesGameInstances::class, CreatesTeams::class);

describe('UserAnonymizationService integration', function () {
    beforeEach(function () {
        Queue::fake([DeletePostHogUserData::class]);
        Log::spy();
    });

    it('hard-deletes Tier 1 private data', function () {
        $user = User::factory()->create([
            'phone' => '+1234567890',
            'gender' => 'non-binary',
            'pronouns' => 'they/them',
            'bio' => 'A test bio',
            'avatar_url' => 'https://example.com/avatar.jpg',
        ]);

        // Create Tier 1 data
        LinkedAccount::create([
            'user_id' => $user->id,
            'provider' => 'discord',
            'provider_user_id' => '12345',
            'token' => 'secret-token',
        ]);

        PushSubscription::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'endpoint' => 'https://fcm.example.com/send/abc',
            'p256h_key' => base64_encode(random_bytes(65)),
            'auth_token' => base64_encode(random_bytes(16)),
        ]);

        NearbyDiscoveryView::create([
            'user_id' => $user->id,
            'last_discovery_view' => now(),
            'geohash_4' => 'gbsw',
        ]);

        DB::table('user_game_system_preferences')->insert([
            'user_id' => $user->id,
            'game_system_id' => GameSystem::factory()->create()->id,
            'preference_type' => 'favorite',
        ]);

        UserAppVisit::create([
            'user_id' => $user->id,
            'visit_date' => now()->format('Y-m-d'),
        ]);

        $membershipType = MembershipType::factory()->create();
        LocalSubscription::create([
            'user_id' => $user->id,
            'membership_type_id' => $membershipType->id,
            'status' => 'active',
            'starts_at' => now(),
        ]);

        GmSocialLink::create([
            'user_id' => $user->id,
            'platform' => 'discord',
            'handle' => 'testuser',
        ]);

        // Verify data exists before anonymization
        expect(DB::table('linked_accounts')->where('user_id', $user->id)->count())->toBe(1)
            ->and(DB::table('push_subscriptions')->where('user_id', $user->id)->count())->toBe(1)
            ->and(DB::table('nearby_discovery_views')->where('user_id', $user->id)->count())->toBe(1)
            ->and(DB::table('user_game_system_preferences')->where('user_id', $user->id)->count())->toBe(1)
            ->and(DB::table('user_app_visits')->where('user_id', $user->id)->count())->toBe(1)
            ->and(DB::table('local_subscriptions')->where('user_id', $user->id)->count())->toBe(1)
            ->and(DB::table('gm_social_links')->where('user_id', $user->id)->count())->toBe(1);

        // Execute
        $service = app(UserAnonymizationService::class);
        $service->anonymize($user);

        // Assert all Tier 1 data deleted
        expect(DB::table('linked_accounts')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('push_subscriptions')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('nearby_discovery_views')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('user_game_system_preferences')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('user_app_visits')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('local_subscriptions')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('gm_social_links')->where('user_id', $user->id)->count())->toBe(0);
    });

    it('preserves games and game participations', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $system = GameSystem::factory()->create();
        $game = $this->createFullGame($owner, $system, maxPlayers: 3);

        $gameId = $game->id;
        $participantCount = GameParticipant::where('game_id', $gameId)->count();

        $service = app(UserAnonymizationService::class);
        $service->anonymize($owner);

        // Game still exists
        expect(Game::find($gameId))->not->toBeNull();

        // All participants still exist
        expect(GameParticipant::where('game_id', $gameId)->count())->toBe($participantCount);

        // Owner has no participant record (implicit in production code)
        $ownerParticipant = GameParticipant::where('game_id', $gameId)
            ->where('user_id', $owner->id)
            ->first();
        expect($ownerParticipant)->toBeNull('Owner should not have a participant record');
    });

    it('preserves campaigns and campaign participations', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => GameSystem::factory()->create()->id,
        ]);

        $participant = CampaignParticipant::create([
            'id' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $campaignId = $campaign->id;
        $participantId = $participant->id;

        $service = app(UserAnonymizationService::class);
        $service->anonymize($owner);

        expect(Campaign::find($campaignId))->not->toBeNull()
            ->and(CampaignParticipant::find($participantId))->not->toBeNull();
    });

    it('preserves reviews and reviewer relationship resolves normally', function () {
        $reviewer = User::factory()->create(['profile_complete' => true]);
        $gmUser = $this->createSubscribedGm();
        $gmProfile = $gmUser->gmProfile;

        $game = Game::factory()->create([
            'owner_id' => $gmUser->id,
            'game_system_id' => GameSystem::factory()->create()->id,
        ]);

        $review = Review::create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $game->id,
            'reviewer_id' => $reviewer->id,
            'gm_profile_id' => $gmProfile->id,
            'rating' => 5,
            'body' => 'Great game!',
            'status' => 'published',
        ]);

        $reviewId = $review->id;
        $reviewerId = $reviewer->id;

        $service = app(UserAnonymizationService::class);
        $service->anonymize($reviewer);

        // Review row preserved with reviewer_id intact
        $fresh = Review::find($reviewId);
        expect($fresh)->not->toBeNull()
            ->and($fresh->reviewer_id)->toBe($reviewerId);

        // Reviewer resolves as 'Deleted User' — no global scope needed
        $loaded = Review::with('reviewer')->find($reviewId);
        expect($loaded->reviewer)->not->toBeNull()
            ->and($loaded->reviewer->name)->toBe('Deleted User');
    });

    it('preserves team memberships', function () {
        ['captain' => $captain, 'team' => $team] = $this->createTeamWithCaptain();

        $teamId = $team->id;
        $captainId = $captain->id;

        $service = app(UserAnonymizationService::class);
        $service->anonymize($captain);

        expect(\App\Models\Team::find($teamId))->not->toBeNull();

        $member = TeamMember::where('team_id', $teamId)
            ->where('user_id', $captainId)
            ->first();
        expect($member)->not->toBeNull();
    });

    it('preserves event registrations', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        $event = Event::factory()->create();
        $registration = EventRegistration::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => 'confirmed',
            'payment_status' => 'paid',
        ]);

        $registrationId = $registration->id;

        $service = app(UserAnonymizationService::class);
        $service->anonymize($user);

        expect(EventRegistration::find($registrationId))->not->toBeNull();
    });

    it('preserves attendance reports', function () {
        $reporter = User::factory()->create();
        $reported = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $reporter->id,
            'game_system_id' => GameSystem::factory()->create()->id,
        ]);

        $report = AttendanceReport::create([
            'game_id' => $game->id,
            'reporter_id' => $reporter->id,
            'reported_id' => $reported->id,
            'status' => 'attended',
            'weight_applied' => 1.0,
            'is_corroborated' => false,
            'quarantined' => false,
        ]);

        $reportId = $report->id;

        $service = app(UserAnonymizationService::class);
        $service->anonymize($reported);

        expect(AttendanceReport::find($reportId))->not->toBeNull();
    });

    it('preserves activity logs', function () {
        $user = User::factory()->create();

        // Use DB::table to avoid Eloquent enum casting on event_type
        DB::table('activity_logs')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'event_type' => ActivityType::PlayerJoined->value,
            'properties' => json_encode(['game_id' => 'test']),
            'created_at' => now(),
        ]);

        $userId = $user->id;

        $service = app(UserAnonymizationService::class);
        $service->anonymize($user);

        expect(DB::table('activity_logs')->where('user_id', $userId)->count())->toBe(1);
    });

    it('strips all PII from user row and sets anonymized_at', function () {
        $user = User::factory()->create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+1234567890',
            'gender' => 'female',
            'pronouns' => 'she/her',
            'bio' => 'A passionate gamer',
            'avatar_url' => 'https://example.com/avatar.jpg',
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $userId = $user->id;

        $service = app(UserAnonymizationService::class);
        $service->anonymize($user);

        $fresh = User::find($userId);

        expect($fresh->name)->toBe('Deleted User')
            ->and($fresh->email)->toMatch('/^deleted-[0-9a-f-]+@deleted\.roundup\.games$/')
            ->and($fresh->phone)->toBeNull()
            ->and($fresh->gender)->toBeNull()
            ->and($fresh->pronouns)->toBeNull()
            ->and($fresh->avatar_url)->toBeNull()
            ->and($fresh->bio)->toBeNull()
            ->and($fresh->location)->toBeNull()
            ->and($fresh->location_id)->toBeNull()
            ->and($fresh->anonymized_at)->not->toBeNull()
            ->and($fresh->email_verified_at)->toBeNull()
            ->and($fresh->profile_complete)->toBeFalse()
            ->and($fresh->isAnonymized())->toBeTrue();
    });

    it('excludes anonymized user from notAnonymized scope', function () {
        $active = User::factory()->create(['name' => 'Active User']);
        $target = User::factory()->create(['name' => 'Target User']);
        $targetId = $target->id;

        $service = app(UserAnonymizationService::class);
        $service->anonymize($target);

        $allIds = User::pluck('id');
        $scopedIds = User::notAnonymized()->pluck('id');

        expect($allIds)->toContain($active->id, $targetId)
            ->and($scopedIds)->toContain($active->id)
            ->and($scopedIds)->not->toContain($targetId);
    });

    it('resolves anonymized user through eager-loaded relationships', function () {
        $owner = User::factory()->create([
            'profile_complete' => true,
            'name' => 'Game Owner',
        ]);
        $system = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $gameId = $game->id;

        $service = app(UserAnonymizationService::class);
        $service->anonymize($owner);

        // Load game with participants and their user — resolves normally
        // since there's no global scope blocking anonymized user resolution.
        $loadedGame = Game::with('participants.user')->find($gameId);

        expect($loadedGame)->not->toBeNull();

        $participantRecord = $loadedGame->participants->firstWhere('user_id', $owner->id);
        expect($participantRecord)->not->toBeNull()
            ->and($participantRecord->user)->not->toBeNull()
            ->and($participantRecord->user->name)->toBe('Deleted User');
    });

    it('deletes sessions for the anonymized user', function () {
        $user = User::factory()->create();

        DB::table('sessions')->insert([
            'id' => Str::random(40),
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestAgent',
            'payload' => Str::random(100),
            'last_activity' => now()->timestamp,
        ]);

        $userId = $user->id;

        $service = app(UserAnonymizationService::class);
        $service->anonymize($user);

        expect(DB::table('sessions')->where('user_id', $userId)->count())->toBe(0);
    });

    it('dispatches PostHog deletion job when user had analytics consent', function () {
        Queue::fake([DeletePostHogUserData::class]);

        $user = User::factory()->create(['analytics_consent' => true]);

        $service = app(UserAnonymizationService::class);
        $service->anonymize($user);

        Queue::assertPushed(DeletePostHogUserData::class);
    });

    it('does not dispatch PostHog deletion when user had no analytics consent', function () {
        Queue::fake([DeletePostHogUserData::class]);

        $user = User::factory()->create(['analytics_consent' => false]);

        $service = app(UserAnonymizationService::class);
        $service->anonymize($user);

        Queue::assertNotPushed(DeletePostHogUserData::class);
    });

    it('runs full data graph scenario end-to-end', function () {
        // ── Setup: Create a user with a rich data graph ──

        $user = $this->createSubscribedGm(
            ['name' => 'Rich User', 'phone' => '+15551234567', 'bio' => 'Test bio', 'profile_complete' => true],
        );
        $userId = $user->id;

        // Tier 1: linked account
        LinkedAccount::create([
            'user_id' => $userId,
            'provider' => 'google',
            'provider_user_id' => 'google-123',
            'token' => 'secret-google-token',
        ]);

        // Tier 1: push subscription
        PushSubscription::create([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'endpoint' => 'https://fcm.example.com/test',
            'p256h_key' => base64_encode(random_bytes(65)),
            'auth_token' => base64_encode(random_bytes(16)),
        ]);

        // Tier 2: game with participation
        $system = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $userId,
            'game_system_id' => $system->id,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $userId,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Tier 2: campaign with participation
        $campaign = Campaign::factory()->create([
            'owner_id' => $userId,
            'game_system_id' => $system->id,
        ]);
        CampaignParticipant::create([
            'id' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'user_id' => $userId,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Tier 2: team membership
        ['captain' => $teamCaptain, 'team' => $team] = $this->createTeamWithCaptain();
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $userId,
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // Tier 2: review written by our user about a GM
        $otherGm = $this->createSubscribedGm();
        $review = Review::create([
            'reviewable_type' => Game::class,
            'reviewable_id' => $game->id,
            'reviewer_id' => $userId,
            'gm_profile_id' => $otherGm->gmProfile->id,
            'rating' => 4,
            'body' => 'Good game',
            'status' => 'published',
        ]);

        // Tier 2: event registration
        $event = Event::factory()->create();
        $eventReg = EventRegistration::factory()->create([
            'user_id' => $userId,
            'event_id' => $event->id,
            'status' => 'confirmed',
        ]);

        // Tier 2: activity log (raw DB insert to avoid enum casting)
        DB::table('activity_logs')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'event_type' => ActivityType::PlayerJoined->value,
            'properties' => json_encode(['game_id' => $game->id]),
            'created_at' => now(),
        ]);

        // Tier 2: attendance report where user is the reported player
        $attendanceReport = AttendanceReport::create([
            'game_id' => $game->id,
            'reporter_id' => $teamCaptain->id,
            'reported_id' => $userId,
            'status' => 'attended',
            'weight_applied' => 1.0,
            'is_corroborated' => false,
            'quarantined' => false,
        ]);

        // Session
        DB::table('sessions')->insert([
            'id' => Str::random(40),
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestAgent',
            'payload' => Str::random(100),
            'last_activity' => now()->timestamp,
        ]);

        // ── Execute ──
        $service = app(UserAnonymizationService::class);
        $service->anonymize($user);

        // ── Assert Tier 1 DELETED ──
        expect(DB::table('linked_accounts')->where('user_id', $userId)->count())->toBe(0)
            ->and(DB::table('push_subscriptions')->where('user_id', $userId)->count())->toBe(0);

        // ── Assert Tier 2/3 PRESERVED ──
        expect(Game::find($game->id))->not->toBeNull()
            ->and(GameParticipant::where('game_id', $game->id)->where('user_id', $userId)->exists())->toBeTrue()
            ->and(Campaign::find($campaign->id))->not->toBeNull()
            ->and(CampaignParticipant::where('campaign_id', $campaign->id)->where('user_id', $userId)->exists())->toBeTrue()
            ->and(Review::find($review->id))->not->toBeNull()
            ->and(TeamMember::where('team_id', $team->id)->where('user_id', $userId)->exists())->toBeTrue()
            ->and(EventRegistration::find($eventReg->id))->not->toBeNull()
            ->and(DB::table('activity_logs')->where('user_id', $userId)->count())->toBeGreaterThanOrEqual(1)
            ->and(AttendanceReport::find($attendanceReport->id))->not->toBeNull();

        // ── Assert user PII stripped ──
        $fresh = User::find($userId);
        expect($fresh->name)->toBe('Deleted User')
            ->and($fresh->email)->toMatch('/^deleted-[0-9a-f-]+@deleted\.roundup\.games$/')
            ->and($fresh->phone)->toBeNull()
            ->and($fresh->gender)->toBeNull()
            ->and($fresh->pronouns)->toBeNull()
            ->and($fresh->avatar_url)->toBeNull()
            ->and($fresh->bio)->toBeNull()
            ->and($fresh->location)->toBeNull()
            ->and($fresh->location_id)->toBeNull()
            ->and($fresh->anonymized_at)->not->toBeNull()
            ->and($fresh->isAnonymized())->toBeTrue();

        // ── Assert notAnonymized scope excludes user ──
        expect(User::notAnonymized()->pluck('id'))->not->toContain($userId);

        // ── Assert eager-loaded relationships resolve normally ──
        $loadedGame = Game::with('participants.user')->find($game->id);
        $participantRecord = $loadedGame->participants->firstWhere('user_id', $userId);
        expect($participantRecord)->not->toBeNull()
            ->and($participantRecord->user)->not->toBeNull()
            ->and($participantRecord->user->name)->toBe('Deleted User');

        // Review still loads reviewer as Deleted User
        $loadedReview = Review::with('reviewer')->find($review->id);
        expect($loadedReview->reviewer)->not->toBeNull()
            ->and($loadedReview->reviewer->name)->toBe('Deleted User');

        // Sessions deleted
        expect(DB::table('sessions')->where('user_id', $userId)->count())->toBe(0);
    });
});
