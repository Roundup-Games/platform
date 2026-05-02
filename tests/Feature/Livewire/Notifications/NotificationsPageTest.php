<?php

namespace Tests\Feature\Livewire\Notifications;

use App\Livewire\Notifications\NotificationsPage;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class NotificationsPageTest extends TestCase
{
    use DatabaseTransactions;
    use SetsUpLocale {
        SetsUpLocale::setUp as setUpLocale;
    }

    private User $user;

    protected function setUp(): void
    {
        $this->setUpLocale();
        $this->user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
    }

    // ── Component Mount ────────────────────────────────

    public function test_component_renders_successfully(): void
    {
        Livewire::actingAs($this->user)
            ->test(NotificationsPage::class)
            ->assertOk();
    }

    public function test_shows_page_title(): void
    {
        Livewire::actingAs($this->user)
            ->test(NotificationsPage::class)
            ->assertSee('Notifications');
    }

    // ── Notifications Data ─────────────────────────────

    public function test_shows_empty_state_when_no_notifications_exist(): void
    {
        Livewire::actingAs($this->user)
            ->test(NotificationsPage::class)
            ->assertSee('No notifications yet');
    }

    public function test_displays_grouped_notifications(): void
    {
        $follower = User::factory()->create(['name' => 'Alice']);
        $this->user->notify(new \App\Notifications\NewFollower($follower));

        $component = Livewire::actingAs($this->user)
            ->test(NotificationsPage::class);

        $notifications = $component->get('notifications');
        $this->assertCount(1, $notifications->items());

        $group = $notifications->items()[0];
        $this->assertEquals('NewFollower', $group->type);
        $this->assertEquals('Alice followed you', $group->display_string);
        $this->assertEquals(1, $group->count);
    }

    public function test_renders_notification_display_string(): void
    {
        $follower = User::factory()->create(['name' => 'Bob']);
        $this->user->notify(new \App\Notifications\NewFollower($follower));

        Livewire::actingAs($this->user)
            ->test(NotificationsPage::class)
            ->assertSee('Bob followed you');
    }

    public function test_collapses_same_type_same_day_into_one_group(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $follower = User::factory()->create(['name' => "Follower{$i}"]);
            $this->user->notify(new \App\Notifications\NewFollower($follower));
        }

        $component = Livewire::actingAs($this->user)
            ->test(NotificationsPage::class);

        $notifications = $component->get('notifications');
        $this->assertCount(1, $notifications->items());

        $group = $notifications->items()[0];
        $this->assertEquals(5, $group->count);
    }

    public function test_shows_count_badge_for_grouped_notifications(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $follower = User::factory()->create(['name' => "Follower{$i}"]);
            $this->user->notify(new \App\Notifications\NewFollower($follower));
        }

        Livewire::actingAs($this->user)
            ->test(NotificationsPage::class)
            ->assertSee('3 notifications');
    }

    // ── Unread Count ───────────────────────────────────

    public function test_shows_zero_unread_count_initially(): void
    {
        $component = Livewire::actingAs($this->user)
            ->test(NotificationsPage::class);

        $this->assertEquals(0, $component->get('unreadCount'));
    }

    public function test_computes_correct_unread_count(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $follower = User::factory()->create(['name' => "Follower{$i}"]);
            $this->user->notify(new \App\Notifications\NewFollower($follower));
        }

        $component = Livewire::actingAs($this->user)
            ->test(NotificationsPage::class);

        $this->assertEquals(4, $component->get('unreadCount'));
    }

    public function test_shows_mark_all_read_button_when_unread(): void
    {
        $follower = User::factory()->create(['name' => 'Alice']);
        $this->user->notify(new \App\Notifications\NewFollower($follower));

        Livewire::actingAs($this->user)
            ->test(NotificationsPage::class)
            ->assertSee('Mark all read');
    }

    public function test_hides_mark_all_read_button_when_no_unread(): void
    {
        Livewire::actingAs($this->user)
            ->test(NotificationsPage::class)
            ->assertDontSee('Mark all read');
    }

    // ── Mark As Read ───────────────────────────────────

    public function test_marks_single_group_as_read(): void
    {
        $follower = User::factory()->create(['name' => 'Charlie']);
        $this->user->notify(new \App\Notifications\NewFollower($follower));

        $notification = $this->user->notifications()->first();
        $dateString = $notification->created_at->toDateString();
        $groupKey = "NewFollower_{$dateString}";

        $component = Livewire::actingAs($this->user)
            ->test(NotificationsPage::class);

        $this->assertEquals(1, $component->get('unreadCount'));

        $component->call('markAsRead', $groupKey);

        $this->assertEquals(0, $component->get('unreadCount'));
        $this->assertEquals(0, $this->user->unreadNotifications()->count());
    }

    public function test_dispatches_event_when_marking_group_as_read(): void
    {
        $follower = User::factory()->create(['name' => 'Dave']);
        $this->user->notify(new \App\Notifications\NewFollower($follower));

        $notification = $this->user->notifications()->first();
        $dateString = $notification->created_at->toDateString();
        $groupKey = "NewFollower_{$dateString}";

        Livewire::actingAs($this->user)
            ->test(NotificationsPage::class)
            ->call('markAsRead', $groupKey)
            ->assertDispatched('notification-read');
    }

    public function test_handles_unknown_type_gracefully(): void
    {
        Livewire::actingAs($this->user)
            ->test(NotificationsPage::class)
            ->call('markAsRead', 'UnknownType_2026-01-01')
            ->assertHasNoErrors();
    }

    // ── Mark All Read ──────────────────────────────────

    public function test_marks_all_notifications_as_read(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $follower = User::factory()->create(['name' => "Follower{$i}"]);
            $this->user->notify(new \App\Notifications\NewFollower($follower));
        }

        $component = Livewire::actingAs($this->user)
            ->test(NotificationsPage::class);

        $this->assertEquals(3, $component->get('unreadCount'));

        $component->call('markAllRead');

        $this->assertEquals(0, $component->get('unreadCount'));
        $this->assertEquals(0, $this->user->unreadNotifications()->count());
    }

    public function test_dispatches_event_when_marking_all_read(): void
    {
        $follower = User::factory()->create(['name' => 'Eve']);
        $this->user->notify(new \App\Notifications\NewFollower($follower));

        Livewire::actingAs($this->user)
            ->test(NotificationsPage::class)
            ->call('markAllRead')
            ->assertDispatched('notifications-all-read');
    }

    // ── Group Expand/Collapse ──────────────────────────

    public function test_toggles_group_expansion(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $follower = User::factory()->create(['name' => "Follower{$i}"]);
            $this->user->notify(new \App\Notifications\NewFollower($follower));
        }

        $notification = $this->user->notifications()->first();
        $dateString = $notification->created_at->toDateString();
        $groupKey = "NewFollower_{$dateString}";

        $component = Livewire::actingAs($this->user)
            ->test(NotificationsPage::class);

        // Initially not expanded
        $this->assertEquals([], $component->get('expandedGroups'));

        // Expand
        $component->call('toggleGroup', $groupKey);
        $this->assertArrayHasKey($groupKey, $component->get('expandedGroups'));

        // Collapse
        $component->call('toggleGroup', $groupKey);
        $this->assertEquals([], $component->get('expandedGroups'));
    }

    // ── Refresh / Polling ──────────────────────────────

    public function test_refreshes_notifications_on_poll(): void
    {
        $component = Livewire::actingAs($this->user)
            ->test(NotificationsPage::class);

        // Add notification after mount
        $follower = User::factory()->create(['name' => 'LateFollower']);
        $this->user->notify(new \App\Notifications\NewFollower($follower));

        $component->call('refreshNotifications');

        $notifications = $component->get('notifications');
        $this->assertCount(1, $notifications->items());
    }

    // ── Event Listener ─────────────────────────────────

    public function test_refreshes_on_notification_received_event(): void
    {
        $component = Livewire::actingAs($this->user)
            ->test(NotificationsPage::class);

        $this->assertEquals(0, $component->get('unreadCount'));

        $follower = User::factory()->create(['name' => 'NewFollower']);
        $this->user->notify(new \App\Notifications\NewFollower($follower));

        $component->dispatch('notification-received');

        $this->assertEquals(1, $component->get('unreadCount'));
    }

    // ── Route Integration ──────────────────────────────

    public function test_accessible_via_get_request(): void
    {
        $this->actingAs($this->user)
            ->get(route('notifications.index'))
            ->assertOk();
    }

    public function test_requires_authentication(): void
    {
        $this->get(route('notifications.index'))
            ->assertRedirect(route('login'));
    }

    // ── Read State Visuals ─────────────────────────────

    public function test_shows_unread_indicator_for_unread_groups(): void
    {
        $follower = User::factory()->create(['name' => 'Alice']);
        $this->user->notify(new \App\Notifications\NewFollower($follower));

        $html = Livewire::actingAs($this->user)
            ->test(NotificationsPage::class)
            ->html();

        $this->assertStringContainsString('border-primary', $html);
    }

    public function test_removes_mark_read_button_after_marking_read(): void
    {
        $follower = User::factory()->create(['name' => 'Alice']);
        $this->user->notify(new \App\Notifications\NewFollower($follower));

        $notification = $this->user->notifications()->first();
        $dateString = $notification->created_at->toDateString();
        $groupKey = "NewFollower_{$dateString}";

        $component = Livewire::actingAs($this->user)
            ->test(NotificationsPage::class);

        // Mark-read button is visible for unread notification
        $component->assertSee('Mark as read');

        $component->call('markAsRead', $groupKey);

        // After marking read, mark-read button should not be present
        $component->assertDontSee('Mark as read');
    }

    // ── Multiple Notification Types ────────────────────

    public function test_groups_different_notification_types_separately(): void
    {
        $follower = User::factory()->create(['name' => 'Alice']);
        $this->user->notify(new \App\Notifications\NewFollower($follower));

        $inviter = User::factory()->create(['name' => 'Bob']);
        $game = Game::factory()->create([
            'owner_id' => $inviter->id,
            'status' => 'scheduled',
        ]);
        $this->user->notify(new \App\Notifications\GameInvitation(
            $game,
            $inviter,
        ));

        $component = Livewire::actingAs($this->user)
            ->test(NotificationsPage::class);

        $notifications = $component->get('notifications');
        $this->assertCount(2, $notifications->items());
    }
}
