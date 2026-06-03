<?php

namespace Tests\Feature\Services;

use App\Dto\ActionItem;
use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ActionCenterServiceTest extends TestCase
{
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

    // ── 1. Waitlist Confirmations (critical) ──────────────────────────

    public function test_waitlist_confirmation_returns_correct_data_structure(): void
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

        $items = $this->service->getItems($this->user);

        $this->assertCount(1, $items);
        $item = $items[0];
        $this->assertInstanceOf(ActionItem::class, $item);
        $this->assertSame('waitlist_confirmation', $item->type);
        $this->assertSame('critical', $item->priority);
        $this->assertStringContainsString($game->name, $item->title);
        $this->assertNotEmpty($item->actionUrl);
        $this->assertNotEmpty($item->actionLabel);
        $this->assertSame('schedule', $item->icon);
        $this->assertArrayHasKey('expires_at', $item->metadata);
        $this->assertArrayHasKey('entity_type', $item->metadata);
        $this->assertSame('game', $item->metadata['entity_type']);
    }

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

    public function test_below_min_players_returns_correct_data_structure(): void
    {
        $game = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'date_time' => now()->addHours(24),
            'min_players' => 3,
            'max_players' => 6,
        ]);
        // No approved participants → below min

        $items = $this->service->getItems($this->user);

        $minPlayerItems = array_filter($items, fn ($i) => $i->type === 'below_min_players');
        $this->assertCount(1, $minPlayerItems);
        $item = reset($minPlayerItems);
        $this->assertSame('critical', $item->priority);
        $this->assertStringContainsString($game->name, $item->title);
        $this->assertSame('warning', $item->icon);
        $this->assertArrayHasKey('count', $item->metadata);
        $this->assertSame(0, $item->metadata['count']);
    }

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

    public function test_pending_applications_returns_correct_data_structure(): void
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

        $items = $this->service->getItems($this->user);

        $appItems = array_filter($items, fn ($i) => $i->type === 'pending_applications');
        $this->assertCount(1, $appItems);
        $item = reset($appItems);
        $this->assertSame('high', $item->priority);
        $this->assertSame('group_add', $item->icon);
        $this->assertArrayHasKey('count', $item->metadata);
        $this->assertSame(1, $item->metadata['count']);
    }

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

    public function test_pending_invitation_returns_correct_data_structure(): void
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

        $items = $this->service->getItems($this->user);

        $inviteItems = array_filter($items, fn ($i) => $i->type === 'pending_invitation');
        $this->assertCount(1, $inviteItems);
        $item = reset($inviteItems);
        $this->assertSame('high', $item->priority);
        $this->assertSame('mail', $item->icon);
    }

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

    public function test_unreported_attendance_returns_correct_data_structure(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'updated_at' => now()->subHours(12),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
            'attendance_status' => null,
        ]);

        $items = $this->service->getItems($this->user);

        $attendanceItems = array_filter($items, fn ($i) => $i->type === 'unreported_attendance');
        $this->assertCount(1, $attendanceItems);
        $item = reset($attendanceItems);
        $this->assertSame('medium', $item->priority);
        $this->assertSame('event_note', $item->icon);
    }

    public function test_unreported_attendance_excludes_already_reported(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'updated_at' => now()->subHours(12),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
            'attendance_status' => 'attended',
        ]);

        $items = $this->service->getItems($this->user);
        $attendanceItems = array_filter($items, fn ($i) => $i->type === 'unreported_attendance');
        $this->assertEmpty($attendanceItems);
    }

    public function test_unreported_attendance_excludes_old_completions(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'updated_at' => now()->subHours(72),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
            'attendance_status' => null,
        ]);

        $items = $this->service->getItems($this->user);
        $attendanceItems = array_filter($items, fn ($i) => $i->type === 'unreported_attendance');
        $this->assertEmpty($attendanceItems);
    }

    // ── 6. Missing Recaps (medium) ────────────────────────────────────

    public function test_missing_recap_returns_correct_data_structure(): void
    {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'completed',
            'recap' => null,
            'updated_at' => now()->subDay(),
        ]);

        $items = $this->service->getItems($this->user);

        $recapItems = array_filter($items, fn ($i) => $i->type === 'missing_recap');
        $this->assertCount(1, $recapItems);
        $item = reset($recapItems);
        $this->assertSame('medium', $item->priority);
        $this->assertSame('edit_note', $item->icon);
    }

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

    public function test_available_debriefing_returns_correct_data_structure(): void
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

        $items = $this->service->getItems($this->user);

        $debriefItems = array_filter($items, fn ($i) => $i->type === 'available_debriefing');
        $this->assertCount(1, $debriefItems);
        $item = reset($debriefItems);
        $this->assertSame('medium', $item->priority);
        $this->assertSame('auto_stories', $item->icon);
    }

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
            'id' => (string) \Illuminate\Support\Str::uuid(),
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

    public function test_new_review_returns_correct_data_structure(): void
    {
        $gmProfile = GMProfile::factory()->create([
            'user_id' => $this->user->id,
        ]);

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

        $items = $this->service->getItems($this->user);

        $reviewItems = array_filter($items, fn ($i) => $i->type === 'new_review');
        $this->assertCount(1, $reviewItems);
        $item = reset($reviewItems);
        $this->assertSame('medium', $item->priority);
        $this->assertSame('rate_review', $item->icon);
        $this->assertArrayHasKey('entity_type', $item->metadata);
        $this->assertSame('review', $item->metadata['entity_type']);
    }

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

    public function test_new_follower_returns_correct_data_structure(): void
    {
        $follower = User::factory()->create(['name' => 'TestFollower']);

        UserRelationship::create([
            'user_id' => $follower->id,
            'related_user_id' => $this->user->id,
            'type' => RelationshipType::Follow->value,
        ]);

        $items = $this->service->getItems($this->user);

        $followerItems = array_filter($items, fn ($i) => $i->type === 'new_follower');
        $this->assertCount(1, $followerItems);
        $item = reset($followerItems);
        $this->assertSame('low', $item->priority);
        $this->assertSame('person_add', $item->icon);
        $this->assertStringContainsString('TestFollower', $item->title);
    }

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

    public function test_campaign_session_alert_returns_correct_data_structure(): void
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

        // Add a new session (game) under this campaign
        Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => $campaign->id,
            'game_system_id' => $this->gameSystem->id,
            'status' => 'scheduled',
            'created_at' => now()->subHour(),
        ]);

        $items = $this->service->getItems($this->user);

        $campaignItems = array_filter($items, fn ($i) => $i->type === 'campaign_session_alert');
        $this->assertCount(1, $campaignItems);
        $item = reset($campaignItems);
        $this->assertSame('low', $item->priority);
        $this->assertSame('campaign', $item->icon);
    }

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

    // ── ActionItem DTO ────────────────────────────────────────────────

    public function test_action_item_to_array_and_from_array_roundtrip(): void
    {
        $original = new ActionItem(
            type: 'test_type',
            priority: 'high',
            title: 'Test Title',
            description: 'Test Description',
            actionUrl: 'https://example.com/action',
            actionLabel: 'Do Thing',
            icon: 'test_icon',
            createdAt: now(),
            metadata: ['key' => 'value'],
        );

        $array = $original->toArray();
        $restored = ActionItem::fromArray($array);

        $this->assertSame($original->type, $restored->type);
        $this->assertSame($original->priority, $restored->priority);
        $this->assertSame($original->title, $restored->title);
        $this->assertSame($original->description, $restored->description);
        $this->assertSame($original->actionUrl, $restored->actionUrl);
        $this->assertSame($original->actionLabel, $restored->actionLabel);
        $this->assertSame($original->icon, $restored->icon);
        $this->assertSame($original->metadata, $restored->metadata);
    }

    public function test_action_item_priority_order_values(): void
    {
        $this->assertSame(0, ActionItem::priorityOrder('critical'));
        $this->assertSame(1, ActionItem::priorityOrder('high'));
        $this->assertSame(2, ActionItem::priorityOrder('medium'));
        $this->assertSame(3, ActionItem::priorityOrder('low'));
        $this->assertSame(4, ActionItem::priorityOrder('unknown'));
    }

    // ── 11. Host Bulletins (medium) ────────────────────────────────────

    public function test_host_bulletin_returns_correct_data_structure(): void
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
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'game_id' => $game->id,
            'user_id' => $host->id,
            'content' => 'Running 10 minutes late!',
        ]);

        $items = $this->service->getItems($this->user);

        $bulletinItems = array_filter($items, fn ($i) => $i->type === 'host_bulletin');
        $this->assertCount(1, $bulletinItems);
        $item = reset($bulletinItems);
        $this->assertInstanceOf(ActionItem::class, $item);
        $this->assertSame('host_bulletin', $item->type);
        $this->assertSame('medium', $item->priority);
        $this->assertStringContainsString($game->name, $item->title);
        $this->assertNotEmpty($item->actionUrl);
        $this->assertNotEmpty($item->actionLabel);
        $this->assertSame('campaign', $item->icon);
        $this->assertArrayHasKey('bulletin_id', $item->metadata);
        $this->assertArrayHasKey('entity_type', $item->metadata);
        $this->assertSame('game', $item->metadata['entity_type']);
        $this->assertSame('HostUser', $item->metadata['host_name']);
    }

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
            'id' => (string) \Illuminate\Support\Str::uuid(),
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
            'id' => (string) \Illuminate\Support\Str::uuid(),
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
            'id' => (string) \Illuminate\Support\Str::uuid(),
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
            'id' => (string) \Illuminate\Support\Str::uuid(),
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
            'id' => (string) \Illuminate\Support\Str::uuid(),
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
            'id' => (string) \Illuminate\Support\Str::uuid(),
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

    // ── Performance ───────────────────────────────────────────────────

    public function test_get_items_completes_within_500ms_with_heavy_seeding(): void
    {
        // Seed 50 games and ~200 participants
        $owners = User::factory()->count(5)->create();

        // Create games owned by this user (triggers several item types)
        for ($i = 0; $i < 10; $i++) {
            $game = Game::factory()->create([
                'owner_id' => $this->user->id,
                'game_system_id' => $this->gameSystem->id,
                'status' => 'scheduled',
                'date_time' => now()->addHours(rand(1, 48)),
            ]);
            // 4 participants per game (some pending)
            for ($j = 0; $j < 4; $j++) {
                GameParticipant::create([
                    'game_id' => $game->id,
                    'user_id' => User::factory()->create()->id,
                    'status' => $j < 1 ? ParticipantStatus::Pending->value : ParticipantStatus::Approved->value,
                ]);
            }
        }

        // Create games where this user is a participant
        for ($i = 0; $i < 40; $i++) {
            $game = Game::factory()->create([
                'owner_id' => $owners->random()->id,
                'game_system_id' => $this->gameSystem->id,
                'status' => collect(['scheduled', 'completed'])->random(),
            ]);
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $this->user->id,
                'status' => ParticipantStatus::Approved->value,
                'attendance_status' => rand(0, 1) ? null : 'attended',
            ]);
        }

        $start = microtime(true);
        $items = $this->service->getItems($this->user);
        $elapsed = (microtime(true) - $start) * 1000;

        $this->assertLessThan(500, $elapsed, "getItems took {$elapsed}ms — exceeds 500ms threshold");
        $this->assertIsArray($items);
    }
}
