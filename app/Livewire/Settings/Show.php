<?php

namespace App\Livewire\Settings;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Services\ProfileVisibilityResolver;
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
    /** @var array<string, mixed> Per-category notification channel preferences */
    public array $notificationSettings = [];

    public bool $privacySaved = false;

    public bool $notificationSaved = false;

    public int $pushSubscriptionCount = 0;

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
                'database' => $storedNotifications[$key]['database'] ?? $defaults[$key]['database'],
                'mail' => $storedNotifications[$key]['mail'] ?? $defaults[$key]['mail'],
                'push' => $storedNotifications[$key]['push'] ?? ($defaults[$key]['push'] ?? false),
            ];
        }

        // Count existing push subscriptions for the subscribe/unsubscribe UI
        $this->pushSubscriptionCount = $user->pushSubscriptions()->count();

        // Check if user has a pending data export request ticket
        $this->hasPendingExportRequest = Ticket::where('requester_type', User::class)
            ->where('requester_id', $user->id)
            ->where('ticket_type', 'data_export_request')
            ->open()
            ->exists();
    }

    public function savePrivacySettings(): void
    {
        $user = authenticatedUser();

        $validated = $this->validate([
            'privacySettings' => ['required', 'array'],
            'privacySettings.*' => ['required', 'string', 'in:everyone,friends,nobody'],
        ]);

        // Only store fields that are part of the known FIELDS constant
        $settings = [];
        foreach (ProfileVisibilityResolver::FIELDS as $field) {
            $settings[$field] = $validated['privacySettings'][$field] ?? 'everyone';
        }

        $user->update(['privacy_settings' => $settings]);

        Log::info('Privacy settings updated', [
            'user_id' => $user->id,
            'settings' => $settings,
        ]);

        $this->privacySaved = true;
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
        ]);

        // Ensure every known category is present with all three channels
        $settings = [];
        $allValues = NotificationCategory::values();
        foreach ($allValues as $categoryValue) {
            $entry = $validated['notificationSettings'][$categoryValue] ?? ['database' => true, 'mail' => false, 'push' => false];
            $settings[$categoryValue] = [
                'database' => (bool) ($entry['database'] ?? true),
                'mail' => (bool) ($entry['mail'] ?? false),
                'push' => (bool) ($entry['push'] ?? false),
            ];
        }

        $user->update(['notification_settings' => $settings]);

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
            Log::error('settings.data_export_department_missing');
            $this->addError('dataExport', __('profile.error_data_export_request_failed'));

            return;
        }

        try {
            $ticket = DB::transaction(function () use ($user, $department) {
                $existing = Ticket::where('requester_type', User::class)
                    ->where('requester_id', $user->id)
                    ->where('ticket_type', 'data_export_request')
                    ->open()
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return null;
                }

                return Ticket::create([
                    'requester_type' => User::class,
                    'requester_id' => $user->id,
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

        $tickets = Ticket::where('requester_type', User::class)
            ->where('requester_id', $user->id)
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get(['id', 'reference', 'subject', 'status', 'priority', 'updated_at']);

        return view('livewire.settings.show', [
            'linkedAccounts' => $user->linkedAccounts()->get(),
            'tickets' => $tickets,
        ]);
    }
}
