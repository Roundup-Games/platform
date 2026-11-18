<?php

use App\Models\EmailSuppression;
use App\Models\User;
use App\Notifications\WeeklyDigest;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/*
 * notifications:weekly-digest — the suppression gate (M081 review).
 *
 * The digest dispatches straight to $user->notify(), bypassing
 * NotificationService::send — which is where every OTHER mail send consults
 * the EmailSuppression gate. Without the command-level gate, a hard-bounced
 * address recorded by the Resend webhook keeps receiving the weekly digest
 * every Monday, permanently re-mailing a mailbox the provider has refused.
 */

beforeEach(function () {
    NotificationFacade::fake();
    Mail::fake();
});

function digestEligibleUser(): User
{
    $user = User::factory()->create(['weekly_digest_enabled' => true]);

    // One unread in-app notification from the past week — the digest's
    // eligibility requirement alongside the flag.
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\EntityInvitation',
        'data' => ['type' => 'game_invitation', 'entity_type' => 'game'],
        'read_at' => null,
    ]);

    return $user;
}

it('sends the digest to an eligible user', function () {
    $user = digestEligibleUser();

    $this->artisan('notifications:weekly-digest')->assertSuccessful();

    NotificationFacade::assertSentTo($user, WeeklyDigest::class);
});

it('sends no digest to a suppressed address', function () {
    $user = digestEligibleUser();

    // The same gate NotificationService applies to notification mail: a
    // hard-bounced address recorded by the Resend webhook.
    EmailSuppression::create([
        'email' => $user->email,
        'reason' => 'hard_bounce',
        'source' => 'resend_webhook',
        'suppressed_at' => now(),
    ]);

    $this->artisan('notifications:weekly-digest')->assertSuccessful();

    NotificationFacade::assertNotSentTo($user, WeeklyDigest::class);
});
