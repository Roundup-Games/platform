<?php

namespace App\Livewire\Settings;

use App\Enums\NotificationCategory;
use App\Models\DiscordGuild;
use App\Models\ShortLink;
use App\Models\User;
use App\Services\ProfileVisibilityResolver;
use App\Services\ShortLinkService;
use App\Services\TicketPayloadRenderer;
use App\Services\UserAnonymizationService;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    // Privacy settings
    /** @var array<string, mixed> Map of field key → visibility level (everyone/friends/nobody) */
    public array $privacySettings = [];

    // Notification settings
    /** @var array<string, array<string, bool>> Per-category notification channel preferences */
    public array $notificationSettings = [];

    public bool $privacySaved = false;

    public bool $notificationSaved = false;

    public bool $weeklyDigestEnabled = true;

    public int $pushSubscriptionCount = 0;

    /**
     * Whether the member has linked a Discord account — gates the Discord
     * column in the notification preferences matrix (D118). Unlinked members
     * never see the toggle (it would be meaningless and confusing); the discord
     * key is still carried in the data model so a future link picks up the
     * category default at read time (MEM856).
     */
    public bool $hasDiscordLinked = false;

    // Password fields
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $showPasswordForm = false;

    // Account deletion
    public bool $showDeleteForm = false;

    public string $delete_password = '';

    public string $delete_confirmation = '';

    #[Locked]
    public bool $hasPendingExportRequest = false;

    #[Locked]
    public bool $userHasPassword;

    /** Absolute URL of the member's active iCal feed token (D123), or null when none exists. */
    #[Locked]
    public ?string $calendarFeedUrl = null;

    public function mount(): void
    {
        $user = authenticatedUser();
        $this->userHasPassword = $user->hasPasswordSet();

        // Initialize privacy settings with defaults for unset fields
        /** @var array<string, mixed> $storedSettings */
        $storedSettings = $user->privacy_settings ?? [];
        foreach (ProfileVisibilityResolver::FIELDS as $field) {
            $default = $field === 'location' ? 'everyone' : 'friends';
            $this->privacySettings[$field] = $storedSettings[$field] ?? $default;
        }

        // Initialize notification settings from stored preferences or defaults
        $rawNotifications = $user->notification_settings;
        /** @var array<string, array<string, mixed>> $storedNotifications */
        $storedNotifications = $rawNotifications ?? [];
        $defaults = NotificationCategory::defaultSettings();
        foreach (NotificationCategory::cases() as $category) {
            $key = $category->value;
            $this->notificationSettings[$key] = [
                'database' => (bool) ($storedNotifications[$key]['database'] ?? $defaults[$key]['database']),
                'mail' => (bool) ($storedNotifications[$key]['mail'] ?? $defaults[$key]['mail']),
                'push' => (bool) ($storedNotifications[$key]['push'] ?? ($defaults[$key]['push'] ?? false)),
                'discord' => (bool) ($storedNotifications[$key]['discord'] ?? ($defaults[$key]['discord'] ?? false)),
            ];
        }

        // Gate the Discord column on a linked account (D118). Computed once at
        // mount; the column is rendered conditionally in the Blade partial.
        $this->hasDiscordLinked = $user->discordLinkedAccount() !== null;

        // Count existing push subscriptions for the subscribe/unsubscribe UI
        $this->pushSubscriptionCount = $user->pushSubscriptions()->count();

        // Load weekly digest preference (defaults to true)
        $this->weeklyDigestEnabled = (bool) ($user->weekly_digest_enabled ?? true);

        // Check if user has a pending data export request ticket
        $this->hasPendingExportRequest = Ticket::whereMorphedTo('requester', $user)
            ->where('ticket_type', 'data_export_request')
            ->open()
            ->exists();

        // Resolve the member's active iCal feed URL (D123), if a token exists.
        $activeToken = $this->activeCalendarToken($user);
        $this->calendarFeedUrl = $activeToken !== null
            ? route('ical.feed', $activeToken->code)
            : null;
    }

    /**
     * Generate (or rotate) the member's personal iCal feed token (D123).
     *
     * Rotates: revokes any existing active token first so there is at most one
     * active feed URL per user — pressing Generate is always safe (it
     * invalidates a leaked URL and issues a fresh code). Reuses the ShortLink
     * tokenization (linkable=User, purpose='ical') consumed by ICalFeedController.
     */
    public function generateCalendarFeedToken(): void
    {
        $user = authenticatedUser();
        $service = app(ShortLinkService::class);

        if ($existing = $this->activeCalendarToken($user)) {
            $service->revokeLink($existing);
        }

        $code = $service->generateUniqueCode();

        $link = $service->createLink($user, $user, [
            'code' => $code,
            'url' => route('ical.feed', $code),
            'purpose' => 'ical',
        ]);

        $this->calendarFeedUrl = route('ical.feed', $link->code);

        Log::info('ical_feed.token_generated', [
            'user_id' => $user->id,
            'link_id' => $link->id,
            'code_prefix' => substr($link->code, 0, 3).'…',
        ]);

        session()->flash('calendar_feed_generated', __('settings.calendar_feed_generated_flash'));
    }

    /**
     * Revoke the member's active iCal feed token (D123).
     *
     * Safe no-op when no token exists. Soft-deletes the token so the feed
     * (GET /calendar/{code}) starts returning 404 immediately.
     */
    public function revokeCalendarFeedToken(): void
    {
        $user = authenticatedUser();

        if (! $existing = $this->activeCalendarToken($user)) {
            return;
        }

        app(ShortLinkService::class)->revokeLink($existing);

        $this->calendarFeedUrl = null;

        Log::info('ical_feed.token_revoked', [
            'user_id' => $user->id,
            'link_id' => $existing->id,
        ]);

        session()->flash('calendar_feed_revoked', __('settings.calendar_feed_revoked_flash'));
    }

    /**
     * The member's currently-active iCal token, or null when none exists.
     * SoftDeletes excludes revoked (soft-deleted) tokens automatically.
     */
    private function activeCalendarToken(User $user): ?ShortLink
    {
        return ShortLink::where('linkable_type', User::class)
            ->where('linkable_id', $user->id)
            ->where('purpose', 'ical')
            ->first();
    }

    public function savePrivacySettings(): void
    {
        $user = authenticatedUser();

        $validated = $this->validate([
            'privacySettings' => ['required', 'array'],
            'privacySettings.*' => ['required', 'string', 'in:everyone,friends,nobody'],
        ]);

        // Only store fields that are part of the known FIELDS constant.
        // The default mirrors mount(): only 'location' defaults to 'everyone',
        // every other field defaults to 'friends'. Keeping these in sync prevents
        // a field missing from the request payload from being silently widened
        // to a more permissive visibility than the user ever chose.
        $settings = [];
        foreach (ProfileVisibilityResolver::FIELDS as $field) {
            $default = $field === 'location' ? 'everyone' : 'friends';
            $settings[$field] = $validated['privacySettings'][$field] ?? $default;
        }

        $user->update(['privacy_settings' => $settings]);

        Log::info('Privacy settings updated', [
            'user_id' => $user->id,
            'settings' => $settings,
        ]);

        $this->privacySaved = true;
    }

    /**
     * Toggle a single channel (database/mail/push) across ALL categories.
     * The most-used notification action on any platform — one click instead
     * of 30 individual toggles. Persists immediately.
     */
    public function toggleChannelGlobally(string $channel): void
    {
        $allValues = NotificationCategory::values();

        // Determine the majority state to decide the toggle direction:
        // if most are on, turn all off; if most are off, turn all on.
        $onCount = 0;
        foreach ($allValues as $key) {
            if (! empty($this->notificationSettings[$key][$channel])) {
                $onCount++;
            }
        }
        $newState = $onCount <= count($allValues) / 2;

        foreach ($allValues as $key) {
            $this->notificationSettings[$key][$channel] = $newState;
        }
    }

    /**
     * Toggle all three channels for every category in a group.
     * Group-level master switch — enables/disables a whole section.
     */
    public function toggleGroup(string $groupKey): void
    {
        $grouped = NotificationCategory::grouped();

        if (! isset($grouped[$groupKey])) {
            return;
        }

        // Determine majority state across the group's categories. Discord is
        // always part of the data model (carried even when the column is hidden
        // for unlinked members), so the master switch toggles all four channels.
        $categoryValues = array_keys($grouped[$groupKey]['options']);
        $onCount = 0;
        $totalChannels = count($categoryValues) * 4;
        foreach ($categoryValues as $key) {
            foreach (['database', 'mail', 'push', 'discord'] as $channel) {
                if (! empty($this->notificationSettings[$key][$channel])) {
                    $onCount++;
                }
            }
        }
        $newState = $onCount <= $totalChannels / 2;

        foreach ($categoryValues as $key) {
            $this->notificationSettings[$key]['database'] = $newState;
            $this->notificationSettings[$key]['mail'] = $newState;
            $this->notificationSettings[$key]['push'] = $newState;
            $this->notificationSettings[$key]['discord'] = $newState;
        }
    }

    public function saveNotificationSettings(): void
    {
        $user = authenticatedUser();

        $validated = $this->validate([
            'notificationSettings' => ['required', 'array'],
            'notificationSettings.*' => ['required', 'array'],
            'notificationSettings.*.database' => ['required', 'boolean'],
            'notificationSettings.*.mail' => ['required', 'boolean'],
            'notificationSettings.*.push' => ['nullable', 'boolean'],
            'notificationSettings.*.discord' => ['nullable', 'boolean'],
        ]);

        // Ensure every known category is present with all four channels. The
        // discord key MUST be rebuilt here or a save round-trip silently drops
        // it (research §10 gotcha) — existing rows without it fall back at read
        // time, but a linked member's explicit toggle would be lost on save.
        $settings = [];
        $allValues = NotificationCategory::values();
        foreach ($allValues as $categoryValue) {
            $entry = $validated['notificationSettings'][$categoryValue] ?? ['database' => true, 'mail' => false, 'push' => false, 'discord' => false];
            $settings[$categoryValue] = [
                'database' => (bool) ($entry['database'] ?? true),
                'mail' => (bool) ($entry['mail'] ?? false),
                'push' => (bool) ($entry['push'] ?? false),
                'discord' => (bool) ($entry['discord'] ?? false),
            ];
        }

        $user->update([
            'notification_settings' => $settings,
            'weekly_digest_enabled' => (bool) $this->weeklyDigestEnabled,
        ]);

        // Refresh push subscription count
        $this->pushSubscriptionCount = $user->pushSubscriptions()->count();

        Log::info('Notification settings updated', [
            'user_id' => $user->id,
        ]);

        $this->notificationSaved = true;
    }

    public function changePassword(): void
    {
        $user = authenticatedUser();

        if ($user->hasPasswordSet()) {
            // Existing password — must confirm current
            $this->validate([
                'current_password' => ['required', 'string'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            if (! Hash::check($this->current_password, (string) $user->password)) {
                Log::warning('Password change failed: incorrect current password', [
                    'user_id' => $user->id,
                ]);

                $this->addError('current_password', 'The provided password is incorrect.');

                return;
            }
        } else {
            // No password set (OAuth user) — just set a new one
            $this->validate([
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);
        }

        $user->update([
            'password' => Hash::make($this->password),
            'password_set_at' => now(),
        ]);

        $this->userHasPassword = true;

        Log::info('Password changed', [
            'user_id' => $user->id,
            'had_password_before' => $user->getOriginal('password_set_at') !== null,
        ]);

        $this->reset(['current_password', 'password', 'password_confirmation', 'showPasswordForm']);
        session()->flash('password_updated', __('auth.flash_password_updated_successfully'));
    }

    public function deleteAccount(): void
    {
        $user = authenticatedUser();

        if ($user->hasPasswordSet()) {
            $this->validate([
                'delete_password' => ['required', 'string'],
            ]);

            if (! Hash::check($this->delete_password, (string) $user->password)) {
                $this->addError('delete_password', 'The provided password is incorrect.');

                return;
            }
        } else {
            $this->validate([
                'delete_confirmation' => ['required', 'string', 'in:DELETE'],
            ], [
                'delete_confirmation.in' => __('profile.content_please_type_delete_to_confirm_account_deletion'),
            ]);
        }

        Log::info('Account anonymization initiated by user', [
            'user_id' => $user->id,
            'had_password' => $user->hasPasswordSet(),
        ]);

        try {
            app(UserAnonymizationService::class)->anonymize($user);
        } catch (\Throwable $e) {
            Log::error('Account anonymization failed from settings UI', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            $this->addError(
                $user->hasPasswordSet() ? 'delete_password' : 'delete_confirmation',
                __('profile.error_account_deletion_failed'),
            );

            return;
        }

        Auth::logout();

        session()->invalidate();
        session()->regenerateToken();

        $this->redirect('/', navigate: false);
    }

    /**
     * Submit a data export request ticket from settings.
     */
    public function requestExport(): void
    {
        $user = authenticatedUser();

        $department = Department::where('name', 'Account Support')->first();
        if (! $department) {
            Log::error('Data export request failed: Account Support department is missing', [
                'user_id' => $user->id,
            ]);
            $this->addError('dataExport', __('profile.error_data_export_request_failed'));

            return;
        }

        try {
            $ticket = DB::transaction(function () use ($user, $department) {
                $existing = Ticket::whereMorphedTo('requester', $user)
                    ->where('ticket_type', 'data_export_request')
                    ->open()
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return null;
                }

                return $user->escalatedTickets()->create([
                    'subject' => "Data Export Request — {$user->name}",
                    'description' => 'User requested a full data export via settings.',
                    'status' => TicketStatus::Open->value,
                    'priority' => TicketPriority::Medium->value,
                    'department_id' => $department->id,
                    'ticket_type' => 'data_export_request',
                    'channel' => TicketChannel::Web->value,
                    'metadata' => TicketPayloadRenderer::dataExportPayload($user),
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('settings.data_export_create_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            $this->addError('dataExport', __('profile.error_data_export_request_failed'));

            return;
        }

        if ($ticket === null) {
            $this->addError('dataExport', __('profile.error_data_export_request_pending'));

            return;
        }

        $this->hasPendingExportRequest = true;

        Log::info('settings.data_export_requested', [
            'ticket_id' => $ticket->id,
            'ticket_reference' => $ticket->reference,
            'user_id' => $user->id,
        ]);

        session()->flash('data_export_requested', __('profile.flash_data_export_requested'));
    }

    public function render(): View
    {
        $user = authenticatedUser();

        $tickets = Ticket::whereMorphedTo('requester', $user)
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get(['id', 'reference', 'subject', 'status', 'priority', 'updated_at']);

        return view('livewire.settings.show', [
            'linkedAccounts' => $user->linkedAccounts()->get(),
            'tickets' => $tickets,
            // Guilds the current user installed the roundup bot into (landlord).
            // Gates the Discord Servers section in the Account tab — empty for
            // non-landlords. owner_user_id is a non-conventional FK, so use the
            // relation-name overload of whereBelongsTo rather than the raw column.
            'discordGuilds' => DiscordGuild::whereBelongsTo($user, 'owner')
                ->orderBy('name')
                ->get(['id', 'guild_id', 'name', 'paused']),
        ]);
    }
}
