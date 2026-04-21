<?php

namespace App\Livewire\Notifications;

use App\Services\NotificationQueryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Full-page notifications history component.
 *
 * Displays all notifications for the authenticated user, grouped by
 * type + calendar day with pagination. Supports mark-as-read actions
 * for individual notifications and bulk mark-all-read.
 */
#[Layout('layouts.app')]
class NotificationsPage extends Component
{
    use WithPagination;

    /**
     * Track which groups are expanded to show individual notification items.
     *
     * @var array<string, bool>
     */
    public array $expandedGroups = [];

    /**
     * The authenticated user (locked to prevent tampering).
     */
    #[Locked]
    public $authUser;

    private NotificationQueryService $queryService;

    public function boot(): void
    {
        $this->queryService = app(NotificationQueryService::class);
    }

    public function mount(): void
    {
        $this->authUser = Auth::user();
    }

    /**
     * Get paginated grouped notifications for the full history view.
     *
     * Each page shows 20 groups (collapsed from raw notifications).
     */
    #[Computed]
    public function notifications()
    {
        return $this->queryService->getPaginatedForUser($this->authUser, perPage: 20);
    }

    /**
     * Get the total unread notification count for the badge.
     */
    #[Computed]
    public function unreadCount(): int
    {
        return $this->queryService->getUnreadCount($this->authUser);
    }

    /**
     * Mark a single notification group as read.
     *
     * Parses the group key to determine type and date, then marks
     * all matching unread notifications as read.
     */
    public function markAsRead(string $groupKey): void
    {
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
        $marked = $this->authUser->unreadNotifications()
            ->where('type', $fullType)
            ->whereDate('created_at', $dateString)
            ->update(['read_at' => now()]);

        if ($marked > 0) {
            Log::info('Notification group marked as read (page)', [
                'user_id' => $this->authUser->id,
                'group_key' => $groupKey,
                'count' => $marked,
            ]);
        }

        // Clear computed caches
        unset($this->notifications, $this->unreadCount);

        // Dispatch event for bell component and analytics
        $this->dispatch('notification-read', groupKey: $groupKey);
    }

    /**
     * Mark all unread notifications as read.
     */
    public function markAllRead(): void
    {
        $marked = $this->authUser->unreadNotifications()->update(['read_at' => now()]);

        Log::info('All notifications marked as read (page)', [
            'user_id' => $this->authUser->id,
            'count' => $marked,
        ]);

        // Clear computed caches
        unset($this->notifications, $this->unreadCount);

        // Dispatch event for bell component and analytics
        $this->dispatch('notifications-all-read');
    }

    /**
     * Toggle a group's expanded state to show/hide individual items.
     */
    public function toggleGroup(string $groupKey): void
    {
        if (isset($this->expandedGroups[$groupKey])) {
            unset($this->expandedGroups[$groupKey]);
        } else {
            $this->expandedGroups[$groupKey] = true;
        }
    }

    /**
     * Refresh notifications — called by wire:poll.
     */
    public function refreshNotifications(): void
    {
        unset($this->notifications, $this->unreadCount);
    }

    /**
     * Listen for notification-sent events to refresh the page.
     */
    #[On('notification-received')]
    public function onNotificationReceived(): void
    {
        unset($this->notifications, $this->unreadCount);
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

    public function render()
    {
        return view('livewire.notifications.notifications-page')
            ->title(__('notifications.page_title'));
    }
}
