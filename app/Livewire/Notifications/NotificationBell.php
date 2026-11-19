<?php

namespace App\Livewire\Notifications;

use App\Dto\NotificationGroup;
use App\Services\NotificationQueryService;
use App\Traits\ResolvesNotificationSettingsLinks;
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
 *
 * Livewire v3 busts per-request #[Computed] caches with unset($this->name);
 * these @property tags document that magic so static analysis sees the unset targets.
 *
 * @property Collection<int, NotificationGroup> $recentNotifications
 */
class NotificationBell extends Component
{
    use ResolvesNotificationSettingsLinks;

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
     * Marks all unread notifications in the group's type+date range as
     * read, scoped to the group's underlying category and entity for
     * BatchedNotification rows (the per-burst grouping key carries both —
     * see NotificationQueryService::groupKeyFor()).
     */
    public function markAsRead(string $groupKey): void
    {
        $user = authenticatedUser();

        $parsed = NotificationQueryService::parseGroupKey($groupKey);
        if ($parsed === null) {
            return;
        }

        [$shortType, $dateString, $category, $entityId] = $parsed;

        // Resolve the full notification class name
        $fullType = $this->resolveFullType($shortType);
        if ($fullType === null) {
            return;
        }

        // Mark matching unread notifications as read
        $query = $user->unreadNotifications()
            ->where('type', $fullType)
            ->whereDate('created_at', $dateString);

        if ($category !== null) {
            $query->where('data->type', $category);
        }

        if ($entityId !== null) {
            $query->where('data->entity_id', $entityId);
        }

        $marked = $query->update(['read_at' => now()]);

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
