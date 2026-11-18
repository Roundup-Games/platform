<?php

namespace Tests\Feature\Livewire\Notifications;

use App\Livewire\Notifications\NotificationBell;
use App\Livewire\Notifications\NotificationsPage;
use App\Livewire\Settings\Show as SettingsShow;
use App\Models\User;
use App\Notifications\AccountSuspended;
use App\Notifications\NewFollower;
use App\Notifications\RecapPosted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Per-category settings shortcut on in-app notification rows (M081/S06/T02).
 *
 * Every bell/page row whose stored data.type resolves to a preference family
 * (enum categories directly, type-only variants via the family mapping, e.g.
 * recap_posted → session_content) renders a manage affordance deep-linking to
 * the anchored preference row on the settings page. Rows without a resolvable
 * family (administrative one-offs like account_suspended, or a missing type)
 * render no link.
 */
class NotificationBellSettingsShortcutTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
    }

    // ── Bell: enum-category rows ───────────────────────

    public function test_bell_row_of_enum_category_links_to_its_preference_anchor(): void
    {
        $follower = User::factory()->create(['name' => 'Alice']);
        $this->user->notify(new NewFollower($follower));

        Livewire::actingAs($this->user)
            ->test(NotificationBell::class)
            ->assertSeeHtml('href="'.route('settings.show', app()->getLocale()).'#category-new_follower"')
            // K004: assemble the expected aria-label from the translator —
            // wording may change freely in lang/.
            ->assertSeeHtml('aria-label="'.__('notifications.aria_manage_category_settings', ['category' => __('notifications.category_new_follower', [], 'en')], 'en').'"');
    }

    public function test_bell_row_of_enum_category_links_are_localized_in_german(): void
    {
        app()->setLocale('de');

        $follower = User::factory()->create(['name' => 'Alice']);
        $this->user->notify(new NewFollower($follower));

        Livewire::actingAs($this->user)
            ->test(NotificationBell::class)
            ->assertSeeHtml('href="'.route('settings.show', 'de').'#category-new_follower"')
            ->assertSeeHtml('aria-label="'.__('notifications.aria_manage_category_settings', ['category' => __('notifications.category_new_follower', [], 'de')], 'de').'"');
    }

    // ── Bell: type-only category rows ──────────────────

    public function test_bell_row_of_type_only_category_links_to_its_preference_family(): void
    {
        $this->seedStoredNotification(RecapPosted::class, [
            'type' => 'recap_posted',
            'entity_name' => 'Weekly Board Night',
            'action_url' => url('/recap/1'),
        ]);

        Livewire::actingAs($this->user)
            ->test(NotificationBell::class)
            ->assertSeeHtml('href="'.route('settings.show', app()->getLocale()).'#category-session_content"')
            ->assertSeeHtml('aria-label="'.__('notifications.aria_manage_category_settings', ['category' => __('notifications.category_session_content', [], 'en')], 'en').'"');
    }

    // ── Bell: unresolvable rows omit the link ──────────

    public function test_bell_row_without_resolvable_category_renders_no_settings_link(): void
    {
        // An unknown/legacy type value with NO preference family — the
        // link is never fabricated for unrecognized vocabulary.
        $this->seedStoredNotification(AccountSuspended::class, [
            'type' => 'not_a_known_vocabulary_type',
            'action_url' => url('/support'),
        ]);

        // A payload without a type discriminator at all must degrade the
        // same way (no fabricated category).
        $this->seedStoredNotification(NewFollower::class, [
            'action_url' => url('/dashboard'),
        ]);

        Livewire::actingAs($this->user)
            ->test(NotificationBell::class)
            ->assertDontSeeHtml('#category-')
            ->assertDontSee(__('notifications.aria_manage_category_settings', ['category' => 'x'], 'en'))
            // The row itself stays functional without the shortcut.
            ->assertSeeHtml("wire:click=\"markAsRead('");
    }

    public function test_bell_row_of_moderation_cluster_links_to_its_real_preference_family(): void
    {
        // account_suspended is DISPATCHED under NotificationCategory::
        // ModerationNotice — the moderation_notice preference row (mail on
        // by default) genuinely governs it, so the shortcut must resolve.
        // Previously the family map omitted the moderation cluster and the
        // affordance silently vanished for exactly this family (M081
        // fifth-pass review).
        $this->seedStoredNotification(AccountSuspended::class, [
            'type' => 'account_suspended',
            'action_url' => url('/support'),
        ]);

        Livewire::actingAs($this->user)
            ->test(NotificationBell::class)
            ->assertSeeHtml('href="'.route('settings.show', app()->getLocale()).'#category-moderation_notice"');
    }

    // ── Notifications page rows ────────────────────────

    public function test_notifications_page_rows_expose_the_same_shortcut(): void
    {
        $follower = User::factory()->create(['name' => 'Alice']);
        $this->user->notify(new NewFollower($follower));

        $this->seedStoredNotification(RecapPosted::class, [
            'type' => 'recap_posted',
            'entity_name' => 'Weekly Board Night',
            'action_url' => url('/recap/1'),
        ]);

        Livewire::actingAs($this->user)
            ->test(NotificationsPage::class)
            ->assertSeeHtml('href="'.route('settings.show', app()->getLocale()).'#category-new_follower"')
            ->assertSeeHtml('href="'.route('settings.show', app()->getLocale()).'#category-session_content"')
            ->assertSee(__('notifications.action_manage_category', [], 'en'));
    }

    public function test_notifications_page_row_without_resolvable_category_renders_no_settings_link(): void
    {
        $this->seedStoredNotification(AccountSuspended::class, [
            'type' => 'not_a_known_vocabulary_type',
            'action_url' => url('/support'),
        ]);

        Livewire::actingAs($this->user)
            ->test(NotificationsPage::class)
            ->assertDontSeeHtml('#category-');
    }

    // ── Settings page anchors ──────────────────────────

    public function test_settings_page_renders_anchors_for_every_linked_preference_family(): void
    {
        Livewire::actingAs($this->user)
            ->test(SettingsShow::class)
            ->assertSeeHtml('id="category-new_follower"')
            ->assertSeeHtml('id="category-session_content"')
            ->assertSeeHtml('id="category-badge_awarded"')
            ->assertSeeHtml('id="category-waitlist_placement"');
    }

    /**
     * Seed a raw stored (in-app) notification row with an explicit payload —
     * the same shape NotificationQueryService groups from — without going
     * through a notification class constructor.
     *
     * @param  array<string, mixed>  $data
     */
    private function seedStoredNotification(string $typeClass, array $data): void
    {
        $this->user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => $typeClass,
            'data' => $data,
            'created_at' => now(),
        ]);
    }
}
