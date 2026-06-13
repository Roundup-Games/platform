<?php

namespace App\Livewire\Notifications;

use App\Dto\NotificationGroup;
use App\Services\NotificationQueryService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Notification bell component for the sidebar.
 *
 * Displays unread count badge, dropdown with recent grouped notifications,
 * and actions to mark individual or all notifications as read.
 * Polls every 30 seconds to refresh unread state.
 */
class NotificationBell extends Component
{
    /**
     * Track the dropdown open state for Alpine.js interaction.
     */
    public bool $dropdownOpen = false;

    /**
     * Cached unread count — refreshed via poll and events.
     */
    public int $unreadCount = 0;

    private NotificationQueryService $queryService;

    public function boot(): void
    {
        $this->queryService = app(NotificationQueryService::class);
    }

    public function mount(): void
    {
        $this->unreadCount = $this->queryService->getUnreadCount(authenticatedUser());
    }

    /**
     * Get recent grouped notifications for the dropdown.
     *
     * @return Collection<int, NotificationGroup>
     */
    #[Computed]
    public function recentNotifications()
    {
        return $this->queryService->getGroupedForUser(authenticatedUser(), limit: 10);
    }

    /**
     * Mark a single notification group as read.
     *
     * Marks all unread notifications in the group's type+date range as read.
     */
    public function markAsRead(string $groupKey): void
    {
        $user = authenticatedUser();

        // Parse group key: "{TypeShortName}_{Y-m-d}"
        $parts = explode('_', $groupKey);
        $dateString = array_pop($parts);
        $shortType = implode('_', $parts);

        // Resolve the full notification class name
        $fullType = $this->resolveFullType($shortType);
        if ($fullType === null) {
            return;
        }

        // Mark matching unread notifications as read
        $marked = $user->unreadNotifications()
            ->where('type', $fullType)
            ->whereDate('created_at', $dateString)
            ->update(['read_at' => now()]);

        if ($marked > 0) {
            Log::info('Notification group marked as read', [
                'user_id' => $user->id,
                'group_key' => $groupKey,
                'count' => $marked,
            ]);
        }

        // Refresh unread count
        $this->unreadCount = $this->queryService->getUnreadCount($user);

        // Clear the computed cache
        unset($this->recentNotifications);

        // Dispatch event for any listeners (e.g. analytics)
        $this->dispatch('notification-read', groupKey: $groupKey);
    }

    /**
     * Mark all unread notifications as read.
     */
    public function markAllRead(): void
    {
        $user = authenticatedUser();

        $marked = $user->unreadNotifications()->update(['read_at' => now()]);

        Log::info('All notifications marked as read', [
            'user_id' => $user->id,
            'count' => $marked,
        ]);

        $this->unreadCount = 0;
        unset($this->recentNotifications);

        $this->dispatch('notifications-all-read');
    }

    /**
     * Refresh unread count — called by wire:poll.
     */
    public function refreshUnreadCount(): void
    {
        $this->unreadCount = $this->queryService->getUnreadCount(authenticatedUser());
        unset($this->recentNotifications);
    }

    /**
     * Listen for notification-sent events to refresh the bell.
     */
    #[On('notification-received')]
    public function onNotificationReceived(): void
    {
        $this->unreadCount = $this->queryService->getUnreadCount(authenticatedUser());
        unset($this->recentNotifications);
    }

    /**
     * Close the dropdown.
     */
    public function closeDropdown(): void
    {
        $this->dropdownOpen = false;
    }

    /**
     * Resolve the short type name back to the full notification class.
     */
    protected function resolveFullType(string $shortType): ?string
    {
        $fullType = "App\\Notifications\\{$shortType}";

        if (class_exists($fullType)) {
            return $fullType;
        }

        return null;
    }

    public function render(): View
    {
        return view('livewire.notifications.notification-bell');
    }
}
