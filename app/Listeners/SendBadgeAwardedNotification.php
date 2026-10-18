<?php

namespace App\Listeners;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Notifications\BadgeAwardedNotification;
use App\Services\Badges\Events\BadgeAwarded;
use App\Services\Badges\Events\BadgeUpgraded;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * The notification half of the badge award moment (M069 spec §2.2): one
 * queued listener behind BadgeAwarded AND BadgeUpgraded, routing the
 * celebratory-but-restrained copy to the recipient through
 * NotificationService under the BadgeAwarded category, so per-category
 * channel preferences apply (in-app + push by default; mail is opt-in
 * and the weekly digest covers it).
 *
 * Registered for award/upgrade only (AppServiceProvider) — lapses and
 * revocations are deliberately quiet (spec §4). The events carry
 * identifiers only; the recipient is re-fetched at delivery time, and a
 * user deleted between dispatch and delivery is skipped quietly (there
 * is no shelf left to celebrate on).
 *
 * ShouldQueue (Horizon in production, sync in tests), modeled on
 * BadgePipeline.
 */
class SendBadgeAwardedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function handle(BadgeAwarded|BadgeUpgraded $event): void
    {
        $user = User::query()->whereKey($event->userId)->first();

        if ($user === null) {
            Log::info('Badge award notification skipped a deleted user', [
                'user_id' => $event->userId,
                'badge_key' => $event->badgeKey,
            ]);

            return;
        }

        $this->notifications->send(
            $user,
            new BadgeAwardedNotification(
                $event->badgeKey,
                $event->tier,
                $event->source,
                $event instanceof BadgeUpgraded,
            ),
            NotificationCategory::BadgeAwarded,
        );
    }
}
