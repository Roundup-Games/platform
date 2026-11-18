<?php

namespace App\Listeners;

use App\Mail\WelcomeEmail;
use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the purpose-written welcome mail (M081/S03) when a user registers.
 *
 * Registered is fired by BOTH signup paths: the manual form
 * (RegisteredUserController::store) and OAuth signup — OAuthController
 * dispatches the event explicitly on its new-user path, after the
 * signup-race/slug-race retry blocks resolve, so a raced duplicate
 * (which exits via linkProviderAndLogin) never reaches the dispatch and
 * can never double-fire the event.
 *
 * Registered by event auto-discovery ONLY — never add an explicit
 * EventFacade::listen() registration, or every signup runs this listener
 * twice (the double-fire invariant is pinned by
 * tests/Feature/Platform/ListenerRegistrationTest.php; see the warning
 * block in AppServiceProvider).
 *
 * ShouldQueue (Horizon in production, sync in tests), modeled on
 * SendBadgeAwardedNotification. Suppressed addresses — hard bounces and
 * spam complaints recorded by the Resend webhook — receive nothing: the
 * same EmailSuppression gate NotificationService applies to notification
 * mail, so a previously-bounced address is never re-mailed here either.
 */
class SendWelcomeEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(Registered $event): void
    {
        // Re-fetch at delivery time: the event is serialized across the
        // queue boundary, and a user deleted between dispatch and delivery
        // is skipped quietly (mirrors SendBadgeAwardedNotification).
        // getAuthIdentifier() — not getKey() — because the event only
        // promises an Authenticatable.
        $userId = $event->user->getAuthIdentifier();
        $user = User::query()->whereKey($userId)->first();

        if ($user === null) {
            Log::info('Welcome mail skipped a deleted user', [
                'user_id' => $userId,
            ]);

            return;
        }

        if (EmailSuppression::isSuppressed($user->email)) {
            Log::info('Welcome mail skipped a suppressed address', [
                'user_id' => $user->id,
            ]);

            return;
        }

        Mail::to($user)->send(new WelcomeEmail($user));
    }
}
