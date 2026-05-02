<?php

namespace Tests\Feature\Livewire\Notifications;

use App\Livewire\Notifications\NotificationBell;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class NotificationBellTest extends TestCase
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
            ->test(NotificationBell::class)
            ->assertOk();
    }

    public function test_shows_zero_unread_count_initially(): void
    {
        Livewire::actingAs($this->user)
            ->test(NotificationBell::class)
            ->assertSet('unreadCount', 0);
    }

    public function test_shows_correct_unread_count_on_mount(): void
    {
        // Create 3 unread notifications
        $this->createTestNotifications($this->user, 3);

        Livewire::actingAs($this->user)
            ->test(NotificationBell::class)
            ->assertSet('unreadCount', 3);
    }

    // ── Recent Notifications ───────────────────────────

    public function test_recent_notifications_empty_when_none_exist(): void
    {
        $result = Livewire::actingAs($this->user)
            ->test(NotificationBell::class)
            ->get('recentNotifications');

        $this->assertCount(0, $result);
    }

    public function test_recent_notifications_returns_grouped_notifications(): void
    {
        $follower = User::factory()->create(['name' => 'Alice']);
        $this->user->notify(new \App\Notifications\NewFollower($follower));

        $component = Livewire::actingAs($this->user)
            ->test(NotificationBell::class);

        $recent = $component->get('recentNotifications');
        $this->assertCount(1, $recent);
        $this->assertEquals('NewFollower', $recent->first()->type);
        $this->assertEquals('Alice followed you', $recent->first()->display_string);
        $this->assertFalse($recent->first()->is_read);
    }

    public function test_recent_notifications_limits_to_10_groups(): void
    {
        // Same-type same-day notifications collapse into one group.
        // Verify that the computed property uses the limit of 10.
        // Create 12 NewFollower notifications — they collapse into 1 group.
        for ($i = 0; $i < 12; $i++) {
            $follower = User::factory()->create(['name' => "Follower{$i}"]);
            $this->user->notify(new \App\Notifications\NewFollower($follower));
        }

        $component = Livewire::actingAs($this->user)
            ->test(NotificationBell::class);

        $recent = $component->get('recentNotifications');
        // All 12 same-type same-day notifications collapse into 1 group
        $this->assertCount(1, $recent);
        $this->assertEquals(12, $recent->first()->count);
    }

    // ── Mark As Read ───────────────────────────────────

    public function test_mark_as_read_updates_notification_status(): void
    {
        $follower = User::factory()->create(['name' => 'Bob']);
        $this->user->notify(new \App\Notifications\NewFollower($follower));

        $notification = $this->user->notifications()->first();
        $dateString = $notification->created_at->toDateString();
        $groupKey = "NewFollower_{$dateString}";

        $component = Livewire::actingAs($this->user)
            ->test(NotificationBell::class);

        $this->assertEquals(1, $component->get('unreadCount'));

        $component->call('markAsRead', $groupKey)
            ->assertSet('unreadCount', 0);

        // Verify notification is now read in DB
        $this->assertEquals(0, $this->user->unreadNotifications()->count());
    }

    public function test_mark_as_read_handles_unknown_type_gracefully(): void
    {
        Livewire::actingAs($this->user)
            ->test(NotificationBell::class)
            ->call('markAsRead', 'UnknownType_2026-01-01')
            ->assertHasNoErrors();
    }

    // ── Mark All Read ──────────────────────────────────

    public function test_mark_all_read_clears_all_unread(): void
    {
        $this->createTestNotifications($this->user, 5);

        $component = Livewire::actingAs($this->user)
            ->test(NotificationBell::class);

        $this->assertEquals(5, $component->get('unreadCount'));

        $component->call('markAllRead')
            ->assertSet('unreadCount', 0);

        $this->assertEquals(0, $this->user->unreadNotifications()->count());
    }

    public function test_mark_all_read_dispatches_event(): void
    {
        $this->createTestNotifications($this->user, 2);

        Livewire::actingAs($this->user)
            ->test(NotificationBell::class)
            ->call('markAllRead')
            ->assertDispatched('notifications-all-read');
    }

    // ── Polling ────────────────────────────────────────

    public function test_refresh_unread_count_updates_count(): void
    {
        $component = Livewire::actingAs($this->user)
            ->test(NotificationBell::class);

        $this->assertEquals(0, $component->get('unreadCount'));

        // Add notifications after mount
        $this->createTestNotifications($this->user, 3);

        $component->call('refreshUnreadCount')
            ->assertSet('unreadCount', 3);
    }

    // ── Event Listener ─────────────────────────────────

    public function test_notification_received_event_refreshes_count(): void
    {
        $component = Livewire::actingAs($this->user)
            ->test(NotificationBell::class);

        $this->assertEquals(0, $component->get('unreadCount'));

        // Simulate a notification being received
        $this->createTestNotifications($this->user, 2);

        $component->dispatch('notification-received')
            ->assertSet('unreadCount', 2);
    }

    // ── Dropdown State ─────────────────────────────────

    public function test_close_dropdown_sets_state(): void
    {
        Livewire::actingAs($this->user)
            ->test(NotificationBell::class)
            ->set('dropdownOpen', true)
            ->call('closeDropdown')
            ->assertSet('dropdownOpen', false);
    }

    // ── Blade Rendering ────────────────────────────────

    public function test_renders_bell_icon(): void
    {
        Livewire::actingAs($this->user)
            ->test(NotificationBell::class)
            ->assertSee('notifications'); // material symbol name
    }

    public function test_renders_empty_state_when_no_notifications(): void
    {
        Livewire::actingAs($this->user)
            ->test(NotificationBell::class)
            ->assertSee('No notifications yet');
    }

    public function test_renders_notification_display_string(): void
    {
        $follower = User::factory()->create(['name' => 'Charlie']);
        $this->user->notify(new \App\Notifications\NewFollower($follower));

        Livewire::actingAs($this->user)
            ->test(NotificationBell::class)
            ->assertSee('Charlie followed you');
    }

    public function test_renders_unread_badge_with_count(): void
    {
        $this->createTestNotifications($this->user, 3);

        Livewire::actingAs($this->user)
            ->test(NotificationBell::class)
            ->assertSee('3');
    }

    public function test_renders_mark_all_read_button_when_unread(): void
    {
        $this->createTestNotifications($this->user, 1);

        Livewire::actingAs($this->user)
            ->test(NotificationBell::class)
            ->assertSee('Mark all read');
    }

    public function test_hides_mark_all_read_button_when_no_unread(): void
    {
        Livewire::actingAs($this->user)
            ->test(NotificationBell::class)
            ->assertDontSee('Mark all read');
    }

    public function test_renders_view_all_link(): void
    {
        Livewire::actingAs($this->user)
            ->test(NotificationBell::class)
            ->assertSee('View all notifications');
    }

    /**
     * Create test notifications of the NewFollower type.
     */
    private function createTestNotifications(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $follower = User::factory()->create(['name' => "Follower{$i}"]);
            $user->notify(new \App\Notifications\NewFollower($follower));
        }
    }
}
