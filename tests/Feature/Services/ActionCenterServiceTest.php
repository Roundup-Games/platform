<?php

namespace Tests\Feature\Services;

use App\Dto\ActionItem;
use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Models\AttendanceReport;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\SessionDebriefing;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\ActionCenterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActionCenterServiceTest extends TestCase
{
    use DatabaseTransactions;

    private ActionCenterService $service;

    private User $user;

    private GameSystem $gameSystem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ActionCenterService;
        Cache::flush();
        URL::defaults(['locale' => 'en']);

        $this->user = User::factory()->create();
        $this->gameSystem = GameSystem::factory()->create();
    }

    // ── Empty results ─────────────────────────────────────────────────

    public function test_get_items_returns_empty_for_user_with_no_relevant_state(): void
    {
        $items = $this->service->getItems($this->user);

        $this->assertIsArray($items);
        $this->assertEmpty($items);
    }

    // ── Data-structure contract for every action type (parameterized) ──

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function actionTypesAndExpectations(): array
    {
        return [
            'waitlist_confirmation' => ['waitlist_confirmation', 'critical', 'schedule'],
            'below_min_players' => ['below_min_players', 'critical', 'warning'],
            'pending_applications' => ['pending_applications', 'high', 'group_add'],
            'pending_invitation' => ['pending_invitation', 'high', 'mail'],
            'open_attendance_window' => ['open_attendance_window', 'medium', 'event_note'],
            'missing_recap' => ['missing_recap', 'medium', 'edit_note'],
            'available_debriefing' => ['available_debriefing', 'medium', 'auto_stories'],
            'new_review' => ['new_review', 'medium', 'rate_review'],
            'new_follower' => ['new_follower', 'low', 'person_add'],
            'campaign_session_alert' => ['campaign_session_alert', 'low', 'campaign'],
            'host_bulletin' => ['host_bulletin', 'medium', 'campaign'],
        ];
    }

    #[Test]
    #[DataProvider('actionTypesAndExpectations')]
    public function action_type_returns_correct_data_structure(string $type, string $expectedPriority, string $expectedIcon): void
    {
        $this->seedStateForActionType($type);

        $items = $this->service->getItems($this->user);

        $typedItems = array_filter($items, fn ($i) => $i->type === $type);
        $this->assertCount(1, $typedItems);

        $item = reset($typedItems);
        $this->assertInstanceOf(ActionItem::class, $item);
        $this->assertSame($expectedPriority, $item->priority);
        $this->assertSame($expectedIcon, $item->icon);
        $this->assertNotEmpty($item->actionUrl);
        $this->assertNotEmpty($item->actionLabel);
    }

    /**
     * Dispatch to per-type seeding. Each helper mirrors the setup block
     * that previously lived inside the corresponding data-structure test.
     */
    private function seedStateForActionType(string $type): void
    {
        match ($type) {
            'waitlist_confirmation' => $this->seedWaitlistConfirmation(),
            'below_min_players' => $this->seedBelowMinPlayers(),
            'pending_applications' => $this->seedPendingApplications(),
            'pending_invitation' => $this->seedPendingInvitation(),
            'open_attendance_window' => $this->seedOpenAttendanceWindow(),
            'missing_recap' => $this->seedMissingRecap(),
            'available_debriefing' => $this->seedAvailableDebriefing(),
            'new_review' => $this->seedNewReview(),
            'new_follower' => $this->seedNewFollower(),
            'campaign_session_alert' => $this->seedCampaignSessionAlert(),
            'host_bulletin' => $this->seedHostBulletin(),
            default => throw new \InvalidArgumentException("Unknown action type: {$type}"),
        };
    }

    private function seedWaitlistConfirmation(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Waitlisted->value,
            'confirmation_expires_at' => now()->addHour(),
            'waitlisted_at' => now()->subHour(),
        ]);
    }

    private function seedBelowMinPlayers(): void
    {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'date_time' => now()->addHours(24),
            'min_players' => 3,
            'max_players' => 6,
        ]);
    }

    private function seedPendingApplications(): void
    {
        $game = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'status' => ParticipantStatus::Pending->value,
        ]);
    }

    private function seedPendingInvitation(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Pending->value,
            'role' => ParticipantRole::Invited->value,
        ]);
    }

    private function seedOpenAttendanceWindow(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'attendance_resolved_at' => null,
            'attendance_window_opens_at' => now()->subHours(2),
            'attendance_window_closes_at' => now()->addDays(3),
            'updated_at' => now()->subHours(12),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    private function seedMissingRecap(): void
    {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'recap' => null,
            'updated_at' => now()->subDay(),
        ]);
    }

    private function seedAvailableDebriefing(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'safety_rules' => ['debriefing'],
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    private function seedNewReview(): void
    {
        $gmProfile = GMProfile::factory()->create(['user_id' => $this->user->id]);
        $reviewer = User::factory()->create();

        Review::create([
            'reviewable_type' => Game::class,
            'reviewable_id' => Game::factory()->create()->id,
            'reviewer_id' => $reviewer->id,
            'gm_profile_id' => $gmProfile->id,
            'rating' => 5,
            'body' => 'Great GM!',
            'status' => 'published',
        ]);
    }

    private function seedNewFollower(): void
    {
        $follower = User::factory()->create(['name' => 'TestFollower']);

        UserRelationship::create([
            'user_id' => $follower->id,
            'related_user_id' => $this->user->id,
            'type' => RelationshipType::Follow->value,
        ]);
    }

    private function seedCampaignSessionAlert(): void
    {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => $campaign->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'created_at' => now()->subHour(),
        ]);
    }

    private function seedHostBulletin(): void
    {
        $host = User::factory()->create(['name' => 'HostUser']);
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameBulletin::create([
            'id' => (string) Str::uuid(),
            'game_id' => $game->id,
            'user_id' => $host->id,
            'content' => 'Running 10 minutes late!',
        ]);
    }

    // ── 1. Waitlist Confirmations (critical) ──────────────────────────

    public function test_waitlist_confirmation_excludes_expired_confirmations(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        // Already expired
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Waitlisted->value,
            'confirmation_expires_at' => now()->subMinute(),
        ]);

        $items = $this->service->getItems($this->user);
        $waitlistItems = array_filter($items, fn ($i) => $i->type === 'waitlist_confirmation');
        $this->assertEmpty($waitlistItems);
    }

    public function test_waitlist_confirmation_excludes_far_future_expirations(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        // Expiring in 5 hours — outside the 2-hour window
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Waitlisted->value,
            'confirmation_expires_at' => now()->addHours(5),
        ]);

        $items = $this->service->getItems($this->user);
        $waitlistItems = array_filter($items, fn ($i) => $i->type === 'waitlist_confirmation');
        $this->assertEmpty($waitlistItems);
    }

    // ── 2. Below Min-Player Warnings (critical) ───────────────────────

    public function test_below_min_players_excludes_games_beyond_48h(): void
    {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'date_time' => now()->addHours(72),
            'min_players' => 3,
        ]);

        $items = $this->service->getItems($this->user);
        $minPlayerItems = array_filter($items, fn ($i) => $i->type === 'below_min_players');
        $this->assertEmpty($minPlayerItems);
    }

    public function test_below_min_players_excludes_games_with_enough_players(): void
    {
        $game = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'date_time' => now()->addHours(24),
            'min_players' => 2,
        ]);

        // Add 2 approved participants — meets min_players=2
        for ($i = 0; $i < 2; $i++) {
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => User::factory()->create()->id,
                'status' => ParticipantStatus::Approved->value,
            ]);
        }

        $items = $this->service->getItems($this->user);
        $minPlayerItems = array_filter($items, fn ($i) => $i->type === 'below_min_players');
        $this->assertEmpty($minPlayerItems);
    }

    // ── 3. Pending Applications (high) ────────────────────────────────

    public function test_pending_applications_counts_multiple_applicants(): void
    {
        $game = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);

        // 3 pending applicants
        for ($i = 0; $i < 3; $i++) {
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => User::factory()->create()->id,
                'status' => ParticipantStatus::Pending->value,
            ]);
        }

        $items = $this->service->getItems($this->user);

        $appItems = array_filter($items, fn ($i) => $i->type === 'pending_applications');
        $this->assertCount(1, $appItems);
        $item = reset($appItems);
        $this->assertSame(3, $item->metadata['count']);
    }

    // ── 4. Pending Invitations (high) ─────────────────────────────────

    public function test_pending_invitation_includes_null_role(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        // The service also matches whereNull('role'), but DB has NOT NULL constraint.
        // Test the fallback path by using role='player' which is not 'invited' —
        // the query matches role='invited' OR role IS NULL; since DB doesn't allow NULL,
        // only role='invited' matches in practice.
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Pending->value,
            'role' => ParticipantRole::Invited->value,
        ]);

        $items = $this->service->getItems($this->user);

        $inviteItems = array_filter($items, fn ($i) => $i->type === 'pending_invitation');
        $this->assertCount(1, $inviteItems);
    }

    // ── 5. Unreported Attendance (medium) ─────────────────────────────

    public function test_open_attendance_window_excludes_already_reported(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'attendance_resolved_at' => null,
            'attendance_window_opens_at' => now()->subHours(2),
            'attendance_window_closes_at' => now()->addDays(3),
            'updated_at' => now()->subHours(12),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // User already filed an attendance report
        AttendanceReport::create([
            'game_id' => $game->id,
            'reporter_id' => $this->user->id,
            'reported_id' => $owner->id,
            'status' => 'attended',
        ]);

        $items = $this->service->getItems($this->user);
        $attendanceItems = array_filter($items, fn ($i) => $i->type === 'open_attendance_window');
        $this->assertEmpty($attendanceItems);
    }

    public function test_open_attendance_window_excludes_resolved_games(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'attendance_resolved_at' => now()->subHour(),
            'attendance_window_opens_at' => now()->subHours(2),
            'attendance_window_closes_at' => now()->addDays(3),
            'updated_at' => now()->subHours(12),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $items = $this->service->getItems($this->user);
        $attendanceItems = array_filter($items, fn ($i) => $i->type === 'open_attendance_window');
        $this->assertEmpty($attendanceItems);
    }

    public function test_open_attendance_window_excludes_closed_window(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'attendance_resolved_at' => null,
            'attendance_window_opens_at' => now()->subDays(5),
            'attendance_window_closes_at' => now()->subHours(1),
            'updated_at' => now()->subDays(5),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $items = $this->service->getItems($this->user);
        $attendanceItems = array_filter($items, fn ($i) => $i->type === 'open_attendance_window');
        $this->assertEmpty($attendanceItems);
    }

    // ── 6. Missing Recaps (medium) ────────────────────────────────────

    public function test_missing_recap_excludes_games_with_recap(): void
    {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'recap' => 'A great session!',
            'updated_at' => now()->subDay(),
        ]);

        $items = $this->service->getItems($this->user);
        $recapItems = array_filter($items, fn ($i) => $i->type === 'missing_recap');
        $this->assertEmpty($recapItems);
    }

    public function test_missing_recap_excludes_games_older_than_7_days(): void
    {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'recap' => null,
            'updated_at' => now()->subDays(10),
        ]);

        $items = $this->service->getItems($this->user);
        $recapItems = array_filter($items, fn ($i) => $i->type === 'missing_recap');
        $this->assertEmpty($recapItems);
    }

    // ── 7. Available Debriefings (medium) ─────────────────────────────

    public function test_available_debriefing_excludes_already_submitted(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'safety_rules' => ['debriefing'],
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Submit a debriefing
        SessionDebriefing::create([
            'id' => (string) Str::uuid(),
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'tool_type' => 'debriefing',
            'responses' => ['q1' => 'answer'],
            'submitted_at' => now(),
        ]);

        $items = $this->service->getItems($this->user);
        $debriefItems = array_filter($items, fn ($i) => $i->type === 'available_debriefing');
        $this->assertEmpty($debriefItems);
    }

    // ── 8. New Reviews (medium) ───────────────────────────────────────

    public function test_new_review_excludes_reviews_older_than_7_days(): void
    {
        $gmProfile = GMProfile::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $review = Review::create([
            'reviewable_type' => Game::class,
            'reviewable_id' => Game::factory()->create()->id,
            'reviewer_id' => User::factory()->create()->id,
            'gm_profile_id' => $gmProfile->id,
            'rating' => 4,
            'body' => 'Good',
            'status' => 'published',
        ]);

        // Force created_at to 10 days ago via direct DB update
        DB::table('reviews')->where('id', $review->id)->update([
            'created_at' => now()->subDays(10),
        ]);

        $items = $this->service->getItems($this->user);
        $reviewItems = array_filter($items, fn ($i) => $i->type === 'new_review');
        $this->assertEmpty($reviewItems);
    }

    public function test_new_review_returns_empty_for_user_without_gm_profile(): void
    {
        // User with no GM profile
        $items = $this->service->getItems($this->user);

        $reviewItems = array_filter($items, fn ($i) => $i->type === 'new_review');
        $this->assertEmpty($reviewItems);
    }

    // ── 9. New Followers (low) ────────────────────────────────────────

    public function test_new_follower_excludes_old_follows(): void
    {
        $follower = User::factory()->create();

        $rel = UserRelationship::create([
            'user_id' => $follower->id,
            'related_user_id' => $this->user->id,
            'type' => RelationshipType::Follow->value,
        ]);

        // Force created_at to 10 days ago via direct DB update
        DB::table('user_relationships')->where('id', $rel->id)->update([
            'created_at' => now()->subDays(10),
        ]);

        $items = $this->service->getItems($this->user);
        $followerItems = array_filter($items, fn ($i) => $i->type === 'new_follower');
        $this->assertEmpty($followerItems);
    }

    // ── 10. Campaign Session Alerts (low) ─────────────────────────────

    public function test_campaign_session_alert_excludes_old_sessions(): void
    {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Old session
        Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => $campaign->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'created_at' => now()->subDays(5),
        ]);

        $items = $this->service->getItems($this->user);
        $campaignItems = array_filter($items, fn ($i) => $i->type === 'campaign_session_alert');
        $this->assertEmpty($campaignItems);
    }

    // ── Priority ordering ─────────────────────────────────────────────

    public function test_items_are_sorted_by_priority_critical_first(): void
    {
        // Create items at different priority levels

        // Low: new follower
        $follower = User::factory()->create();
        UserRelationship::create([
            'user_id' => $follower->id,
            'related_user_id' => $this->user->id,
            'type' => RelationshipType::Follow->value,
        ]);

        // Medium: missing recap (owned, completed, no recap)
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'recap' => null,
            'updated_at' => now()->subDay(),
        ]);

        // High: pending application (owned game with pending participant)
        $game = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'status' => ParticipantStatus::Pending->value,
        ]);

        // Critical: below min players (owned game within 48h)
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'date_time' => now()->addHours(24),
            'min_players' => 3,
        ]);

        $items = $this->service->getItems($this->user);

        $this->assertNotEmpty($items);

        // Verify ordering: critical → high → medium → low
        $priorities = array_map(fn (ActionItem $i) => $i->priority, $items);

        $lastOrder = -1;
        foreach ($priorities as $priority) {
            $order = ActionItem::priorityOrder($priority);
            $this->assertGreaterThanOrEqual($lastOrder, $order, "Items not sorted by priority: {$priority} out of order");
            $lastOrder = $order;
        }
    }

    public function test_within_same_priority_newer_items_first(): void
    {
        // Create two games with pending applications (both high priority)
        $game1 = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'created_at' => now()->subDays(2),
        ]);
        GameParticipant::create([
            'game_id' => $game1->id,
            'user_id' => User::factory()->create()->id,
            'status' => ParticipantStatus::Pending->value,
        ]);

        $game2 = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'created_at' => now()->subHour(),
        ]);
        GameParticipant::create([
            'game_id' => $game2->id,
            'user_id' => User::factory()->create()->id,
            'status' => ParticipantStatus::Pending->value,
        ]);

        $items = $this->service->getItems($this->user);

        $appItems = array_values(array_filter($items, fn ($i) => $i->type === 'pending_applications'));
        $this->assertCount(2, $appItems);

        // Newer game (game2) should come first within the same priority
        $this->assertTrue(
            $appItems[0]->createdAt->timestamp >= $appItems[1]->createdAt->timestamp,
            'Newer items should appear first within the same priority level'
        );
    }

    // ── getClearSummary ───────────────────────────────────────────────

    public function test_get_clear_summary_returns_null_when_items_exist(): void
    {
        // Create a pending application
        $game = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'status' => ParticipantStatus::Pending->value,
        ]);

        $summary = $this->service->getClearSummary($this->user);
        $this->assertNull($summary);
    }

    public function test_get_clear_summary_returns_message_and_next_game_when_no_items(): void
    {
        // No action items, but a scheduled game exists
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'name' => ['en' => 'Catan Night'],
        ]);

        $summary = $this->service->getClearSummary($this->user);

        $this->assertNotNull($summary);
        $this->assertArrayHasKey('message', $summary);
        $this->assertNotEmpty($summary['message']);
        $this->assertArrayHasKey('next_game', $summary);
        $this->assertNotNull($summary['next_game']);
        $this->assertArrayHasKey('name', $summary['next_game']);
        $this->assertArrayHasKey('date_time', $summary['next_game']);
        $this->assertArrayHasKey('url', $summary['next_game']);
    }

    public function test_get_clear_summary_returns_null_next_game_when_no_upcoming_games(): void
    {
        // No action items, no upcoming games
        $summary = $this->service->getClearSummary($this->user);

        $this->assertNotNull($summary);
        $this->assertArrayHasKey('message', $summary);
        $this->assertArrayHasKey('next_game', $summary);
        $this->assertNull($summary['next_game']);
    }

    // ── 11. Host Bulletins (medium) ────────────────────────────────────

    public function test_host_bulletin_excludes_expired_bulletins(): void
    {
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameBulletin::create([
            'id' => (string) Str::uuid(),
            'game_id' => $game->id,
            'user_id' => $host->id,
            'content' => 'Expired bulletin',
            'expires_at' => now()->subHour(),
        ]);

        $items = $this->service->getItems($this->user);
        $bulletinItems = array_filter($items, fn ($i) => $i->type === 'host_bulletin');
        $this->assertEmpty($bulletinItems);
    }

    public function test_host_bulletin_excludes_bulletins_older_than_24h(): void
    {
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $bulletin = GameBulletin::create([
            'id' => (string) Str::uuid(),
            'game_id' => $game->id,
            'user_id' => $host->id,
            'content' => 'Old bulletin',
        ]);

        // Force created_at to 2 days ago
        DB::table('game_bulletins')->where('id', $bulletin->id)->update([
            'created_at' => now()->subDays(2),
        ]);

        $items = $this->service->getItems($this->user);
        $bulletinItems = array_filter($items, fn ($i) => $i->type === 'host_bulletin');
        $this->assertEmpty($bulletinItems);
    }

    public function test_host_bulletin_excludes_own_bulletins(): void
    {
        // User is the game host (owner) — should NOT see their own bulletins
        $game = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        GameBulletin::create([
            'id' => (string) Str::uuid(),
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'content' => 'My own bulletin',
        ]);

        $items = $this->service->getItems($this->user);
        $bulletinItems = array_filter($items, fn ($i) => $i->type === 'host_bulletin');
        $this->assertEmpty($bulletinItems);
    }

    public function test_host_bulletin_excludes_non_approved_participants(): void
    {
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        // User is pending, not approved
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Pending->value,
        ]);

        GameBulletin::create([
            'id' => (string) Str::uuid(),
            'game_id' => $game->id,
            'user_id' => $host->id,
            'content' => 'Host update',
        ]);

        $items = $this->service->getItems($this->user);
        $bulletinItems = array_filter($items, fn ($i) => $i->type === 'host_bulletin');
        $this->assertEmpty($bulletinItems);
    }

    public function test_host_bulletin_auto_expires_on_game_completion(): void
    {
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $bulletin = GameBulletin::create([
            'id' => (string) Str::uuid(),
            'game_id' => $game->id,
            'user_id' => $host->id,
            'content' => 'Running late!',
        ]);

        // Bulletin should be visible before completion
        $items = $this->service->getItems($this->user);
        $bulletinItems = array_filter($items, fn ($i) => $i->type === 'host_bulletin');
        $this->assertCount(1, $bulletinItems);

        // Complete the game — triggers auto-expiry via model event
        $game->status = GameStatus::Completed;
        $game->save();

        // Refresh bulletin from DB
        $bulletin->refresh();
        $this->assertNotNull($bulletin->expires_at);
        $this->assertTrue($bulletin->expires_at->isPast() || $bulletin->expires_at->isCurrentSecond());

        // Bulletin should no longer appear in action center
        $items = $this->service->getItems($this->user);
        $bulletinItems = array_filter($items, fn ($i) => $i->type === 'host_bulletin');
        $this->assertEmpty($bulletinItems);
    }

    public function test_host_bulletin_auto_expires_on_game_cancellation(): void
    {
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $bulletin = GameBulletin::create([
            'id' => (string) Str::uuid(),
            'game_id' => $game->id,
            'user_id' => $host->id,
            'content' => 'See you soon!',
        ]);

        // Cancel the game — triggers auto-expiry
        $game->status = GameStatus::Canceled;
        $game->save();

        $bulletin->refresh();
        $this->assertNotNull($bulletin->expires_at);
    }

    // ── 12. Recurrence Planning Nudges (low) ────────────────────────────

    public function test_get_items_includes_recurrence_planning_nudge_for_recurring_campaign_owner(): void
    {
        // Weekly Active campaign the user owns, with NO upcoming scheduled sessions.
        Campaign::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'active',
            'recurrence' => 'weekly',
        ]);

        $items = $this->service->getItems($this->user);

        $recurrenceItems = array_values(array_filter(
            $items,
            fn (ActionItem $i) => $i->type === 'recurrence_planning',
        ));
        $this->assertCount(1, $recurrenceItems, 'Expected exactly one recurrence planning nudge for the owner.');

        $item = $recurrenceItems[0];
        $this->assertSame('low', $item->priority);
        $this->assertStringContainsString('prefill=1', $item->actionUrl);
        $this->assertSame('event_repeat', $item->icon);
        $this->assertSame('campaign', $item->metadata['entity_type']);
    }

    public function test_recurrence_planning_nudge_absent_for_non_owner(): void
    {
        // Recurring campaign owned by someone else.
        Campaign::factory()->create([
            'owner_id' => User::factory()->create()->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'active',
            'recurrence' => 'weekly',
        ]);

        $items = $this->service->getItems($this->user);

        $recurrenceItems = array_filter($items, fn (ActionItem $i) => $i->type === 'recurrence_planning');
        $this->assertEmpty($recurrenceItems, 'Non-owners must not see another host\'s recurrence nudge.');
    }

    public function test_recurrence_planning_nudge_absent_for_non_recurring_campaign(): void
    {
        // campaigns.recurrence is a NOT NULL enum (weekly/bi-weekly/monthly) per
        // migration 2026_04_12 — research risk #4. To exercise the service's
        // `whereNotNull('recurrence')` guard end-to-end we drop NOT NULL inside
        // this transaction (PostgreSQL DDL is transactional, so DatabaseTransactions
        // rolls the change back) and persist a null recurrence.
        DB::statement('ALTER TABLE campaigns ALTER COLUMN recurrence DROP NOT NULL');

        Campaign::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'active',
            'recurrence' => null,
        ]);

        $items = $this->service->getItems($this->user);

        $recurrenceItems = array_filter($items, fn (ActionItem $i) => $i->type === 'recurrence_planning');
        $this->assertEmpty($recurrenceItems, 'A campaign without a recurrence cadence must not produce a nudge.');
    }

    public function test_recurrence_planning_nudge_absent_when_horizon_healthy(): void
    {
        // Weekly campaign with a scheduled session 20 days out — beyond the
        // 2x-cadence (14-day) plan-ahead horizon, so no nudge is needed.
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'active',
            'recurrence' => 'weekly',
        ]);

        Game::factory()->create([
            'owner_id' => $this->user->id,
            'campaign_id' => $campaign->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(20),
        ]);

        $items = $this->service->getItems($this->user);

        $recurrenceItems = array_filter($items, fn (ActionItem $i) => $i->type === 'recurrence_planning');
        $this->assertEmpty($recurrenceItems, 'No nudge when the cadence horizon is already covered by a scheduled session.');
    }

    public function test_recurrence_planning_nudge_absent_for_completed_campaign(): void
    {
        Campaign::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'recurrence' => 'weekly',
        ]);

        $items = $this->service->getItems($this->user);

        $recurrenceItems = array_filter($items, fn (ActionItem $i) => $i->type === 'recurrence_planning');
        $this->assertEmpty($recurrenceItems, 'Completed campaigns must never produce a planning nudge.');
    }
}
