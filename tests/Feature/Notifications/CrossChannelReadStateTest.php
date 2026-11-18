<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationCategory;
use App\Livewire\Notifications\NotificationBell;
use App\Models\Game;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\EntityInvitation;
use App\Notifications\NewFollower;
use App\Services\Notifications\SignedReadMarker;
use App\Services\NotificationService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Minishlink\WebPush\WebPush;

/*
|--------------------------------------------------------------------------
| Cross-channel read-state sync (D288 item 1, M081/S08/T01)
|--------------------------------------------------------------------------
|
| Mail CTAs and push action URLs carry a signed read-marker bound to
| (user, notification class, entity). Landing on it marks the sibling
| in-app notification read via NotificationService::markReadByType and
| 302s to the real target — so the bell badge tells the truth after
| acting on ANY channel.
|
| Sends run through the REAL dispatch surface (NotificationService on the
| sync queue, array mailer, WebPush mocked at the transport — the
| PushChannelTest convention), never a Notification::fake: the sibling
| database row and the actual outbound mail/push payloads are the thing
| under test.
*/

beforeEach(function () {
    app()->setLocale('en');

    // Capture the real Symfony message each notification mail produces.
    $this->capturedMails = collect();
    Event::listen(MessageSending::class, function (MessageSending $event): void {
        $this->capturedMails->push($event->message);
    });

    // Mock the push transport: queueNotification captures the payload JSON
    // the channel would POST to the push service; flush answers empty (all
    // endpoints silent). Everything else — channel resolution, payload
    // build, subscription lookup — runs for real.
    $this->capturedPushPayloads = collect();
    $webPush = \Mockery::mock(WebPush::class);
    $webPush->shouldReceive('queueNotification')->andReturnUsing(
        function ($subscription, ?string $payload, array $options = []) {
            if ($payload !== null) {
                $this->capturedPushPayloads->push(json_decode($payload, true));
            }

            return $webPush;
        },
    );
    $webPush->shouldReceive('flush')->andReturnUsing(function (): \Generator {
        // WebPush::flush() returns a Generator — an array return value makes
        // Mockery's return-type check throw a TypeError, which PushChannel's
        // flush-abort fallback would (correctly) treat as a failed batch.
        yield from [];
    });
    $this->app->instance(WebPush::class, $webPush);

    $this->inviter = User::factory()->create(['profile_complete' => true]);
    $this->recipient = User::factory()->create(['profile_complete' => true]);
    $this->game = Game::factory()->create(['owner_id' => $this->inviter->id]);

    // All three channels explicitly on for the invitation category so the
    // dispatch surface is deterministic regardless of default drift.
    $settings = NotificationCategory::defaultSettings();
    $settings['game_invitation'] = ['database' => true, 'mail' => true, 'push' => true, 'discord' => false];
    $this->recipient->update(['notification_settings' => $settings]);

    // A registered push subscription so PushChannel's send loop reaches the
    // transport (it returns early when a user has no subscriptions). The
    // key material is inert padding — the WebPush transport is mocked, and
    // gitleaks would flag a realistic base64 key literal as a secret.
    PushSubscription::create([
        'user_id' => $this->recipient->id,
        'endpoint' => 'https://push.example.test/send/atom-'.bin2hex(random_bytes(6)),
        'p256h_key' => str_repeat('a', 87),
        'auth_token' => str_repeat('b', 16),
        'user_agent' => 'Pest',
    ]);
});

/** The invitation's database row for the recipient (fresh query helper). */
function invitationRow(User $recipient, ?string $gameId = null)
{
    $query = $recipient->notifications()->where('type', EntityInvitation::class);

    return $gameId === null ? $query->first() : $query->where('data->game_id', $gameId)->first();
}

/** Send a real EntityInvitation through the dispatch surface. */
function sendInvitation(object $test): void
{
    app(NotificationService::class)->send(
        $test->recipient,
        new EntityInvitation($test->game, $test->inviter),
        NotificationCategory::GameInvitation,
    );
}

// ══════════════════════════════════════════════════════
// Happy path: mail CTA and push URL land read
// ══════════════════════════════════════════════════════

it('carries a signed read-marker on the mail CTA and the push action url', function () {
    sendInvitation($this);

    expect($this->capturedMails)->toHaveCount(1)
        ->and($this->capturedPushPayloads)->toHaveCount(1);

    // Mail CTA: the button href in the real outbound message body.
    $html = (string) $this->capturedMails[0]->getHtmlBody();
    expect($html)->toContain('/notifications/read-marker/'.$this->recipient->id);

    preg_match_all('#href="([^"]*\/notifications\/read-marker/[^"]*)"#', $html, $matches);
    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $href) {
        expect(html_entity_decode($href))->toContain('signature=')
            ->toContain('type='.rawurlencode(EntityInvitation::class))
            ->toContain('key=game_id');
    }

    // Push: the payload url the service worker opens on tap.
    $pushUrl = $this->capturedPushPayloads[0]['url'];
    expect($pushUrl)->toContain('/notifications/read-marker/'.$this->recipient->id)
        ->toContain('signature=')
        ->toContain('key=game_id');
});

it('marks the sibling in-app notification read when the mail CTA is clicked', function () {
    sendInvitation($this);

    $html = (string) $this->capturedMails[0]->getHtmlBody();
    preg_match('#href="([^"]*\/notifications\/read-marker/[^"]*)"#', $html, $match);
    $cta = html_entity_decode($match[1]);

    expect(invitationRow($this->recipient)->read_at)->toBeNull();

    $response = $this->get($cta);

    $row = invitationRow($this->recipient);
    expect($row->read_at)->not->toBeNull()
        ->and($row->data['game_id'])->toBe($this->game->id);

    // The landing redirects to the original action target — the localized
    // game page the CTA always pointed at.
    $response->assertRedirect('/en/dashboard/games/'.$this->game->id);
});

it('marks the sibling in-app notification read when the push action is tapped', function () {
    sendInvitation($this);

    $pushUrl = $this->capturedPushPayloads[0]['url'];

    expect(invitationRow($this->recipient)->read_at)->toBeNull();

    $response = $this->get($pushUrl);

    expect(invitationRow($this->recipient)->read_at)->not->toBeNull();
    $response->assertRedirect('/en/dashboard/games/'.$this->game->id);
});

it('binds the marker to the entity — a sibling notification for another game stays unread', function () {
    sendInvitation($this);

    // A second unread invitation row for a different game (the marker's
    // entity binding must scope the marking, not clear the whole type).
    $otherGame = Game::factory()->create(['owner_id' => $this->inviter->id]);
    $this->recipient->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => EntityInvitation::class,
        'data' => [
            'type' => 'game_invitation',
            'game_id' => $otherGame->id,
            'game_name' => $otherGame->name,
        ],
    ]);

    $marker = app(SignedReadMarker::class)->build(
        $this->recipient,
        'en',
        EntityInvitation::class,
        $this->game->id,
        'game_id',
        '/en/dashboard/games/'.$this->game->id,
    );

    $this->get($marker);

    expect(invitationRow($this->recipient, $this->game->id)->read_at)->not->toBeNull()
        ->and(invitationRow($this->recipient, $otherGame->id)->read_at)->toBeNull();
});

it('is idempotent — a repeat click keeps read_at and still redirects', function () {
    $marker = app(SignedReadMarker::class)->build(
        $this->recipient,
        'en',
        EntityInvitation::class,
        $this->game->id,
        'game_id',
        '/en/dashboard/games/'.$this->game->id,
    );

    $this->recipient->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => EntityInvitation::class,
        'data' => ['type' => 'game_invitation', 'game_id' => $this->game->id],
    ]);

    $first = $this->get($marker);
    $readAt = invitationRow($this->recipient)->read_at;
    expect($readAt)->not->toBeNull();

    $second = $this->get($marker);

    expect(invitationRow($this->recipient)->read_at)->toEqual($readAt);
    $first->assertRedirect('/en/dashboard/games/'.$this->game->id);
    $second->assertRedirect('/en/dashboard/games/'.$this->game->id);
});

// ══════════════════════════════════════════════════════
// Authorization shape: the marker IS the authorization
// ══════════════════════════════════════════════════════

it("marks only the marker user's rows — never the authenticated viewer's — when users differ", function () {
    sendInvitation($this);

    // A second user with their own unread invitation row for the SAME game.
    $other = User::factory()->create();
    $other->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => EntityInvitation::class,
        'data' => ['type' => 'game_invitation', 'game_id' => $this->game->id],
    ]);

    $marker = app(SignedReadMarker::class)->build(
        $this->recipient,
        'en',
        EntityInvitation::class,
        $this->game->id,
        'game_id',
        '/en/dashboard/games/'.$this->game->id,
    );

    // Logged in as someone else: the marker is the authorization, and it
    // can only ever write the marker user's rows.
    $this->actingAs($other)->get($marker);

    expect(invitationRow($this->recipient)->read_at)->not->toBeNull()
        ->and(invitationRow($other)->read_at)->toBeNull();
});

// ══════════════════════════════════════════════════════
// Tampering and the open-redirect guard
// ══════════════════════════════════════════════════════

it('rejects a tampered signature with 404 and no read_at change', function () {
    sendInvitation($this);

    $marker = app(SignedReadMarker::class)->build(
        $this->recipient,
        'en',
        EntityInvitation::class,
        $this->game->id,
        'game_id',
        '/en/dashboard/games/'.$this->game->id,
    );

    // Flip one hex char inside the signature (append a constant byte run the
    // original cannot contain at that position, guaranteeing a mismatch).
    $tampered = preg_replace('/(signature=[0-9a-f]+)/i', '$1ff', $marker, 1, $count);
    expect($count)->toBe(1);

    $this->get($tampered)->assertNotFound();

    expect(invitationRow($this->recipient)->read_at)->toBeNull();
});

it('rejects scheme-carrying and protocol-relative redirect targets even with a valid signature', function (string $hostile) {
    sendInvitation($this);

    // signedRoute happily signs whatever params it is handed — the guard
    // exists to catch OUR generation ever passing an unsafe target.
    $marker = URL::signedRoute('notifications.read_marker', [
        'locale' => 'en',
        'user' => $this->recipient->id,
        'type' => EntityInvitation::class,
        'entity' => $this->game->id,
        'key' => 'game_id',
        'redirect' => $hostile,
    ]);

    $response = $this->get($marker);
    $response->assertNotFound();

    // No open redirect and no marking on a rejected landing.
    expect($response->headers->get('Location'))->toBeNull()
        ->and(invitationRow($this->recipient)->read_at)->toBeNull();
})->with([
    'absolute https URL' => ['https://evil.test/phish'],
    'protocol-relative' => ['//evil.test/path'],
    'backslash netloc smuggling' => ['/\\evil.test'],
    'relative without leading slash' => ['en/dashboard/games'],
    'javascript pseudo-scheme' => ['/javascript:alert(1)'],
]);

it('rejects a marker without a redirect param', function () {
    $marker = URL::signedRoute('notifications.read_marker', [
        'locale' => 'en',
        'user' => $this->recipient->id,
        'type' => EntityInvitation::class,
        'entity' => $this->game->id,
        'key' => 'game_id',
    ]);

    $this->get($marker)->assertNotFound();
});

it('marks all unread rows of the type when the notification carries no entity binding', function () {
    // NewFollower's database payload has no whitelisted id key: the marker
    // binds user+type only, so clicking marks every unread row of that type.
    $follower = User::factory()->create();
    $this->recipient->notifications()->createMany([
        ['id' => (string) Str::uuid(), 'type' => NewFollower::class, 'data' => ['type' => 'new_follower', 'follower_id' => $follower->id]],
        ['id' => (string) Str::uuid(), 'type' => NewFollower::class, 'data' => ['type' => 'new_follower', 'follower_id' => $this->inviter->id]],
    ]);

    $marker = app(SignedReadMarker::class)->build(
        $this->recipient,
        'en',
        NewFollower::class,
        null,
        null,
        '/en/dashboard',
    );

    $this->get($marker);

    expect($this->recipient->unreadNotifications()->where('type', NewFollower::class)->count())->toBe(0);
});

// ══════════════════════════════════════════════════════
// Badge truth (M081/S08/T02): the bell reflects cross-channel clearing
// ══════════════════════════════════════════════════════

it('tells the badge truth across channels: a marker click and an in-app mark both clear the count', function () {
    // Two unread notifications through the real dispatch surface — one
    // invitation per game, so the marker's entity binding clears exactly one.
    sendInvitation($this);
    $otherGame = Game::factory()->create(['owner_id' => $this->inviter->id]);
    app(NotificationService::class)->send(
        $this->recipient,
        new EntityInvitation($otherGame, $this->inviter),
        NotificationCategory::GameInvitation,
    );

    expect($this->recipient->unreadNotifications()->count())->toBe(2);

    $bell = Livewire::actingAs($this->recipient)
        ->test(NotificationBell::class)
        ->assertSet('unreadCount', 2);

    // Cross-channel half: the user acts on another channel (mail/push
    // landing); an already-open bell re-renders via its wire:poll path and
    // must show the externally-cleared state. The badge count is a fresh
    // read_at IS NULL COUNT (NotificationQueryService::getUnreadCount) —
    // nothing cached survives the external marking.
    $marker = app(SignedReadMarker::class)->build(
        $this->recipient,
        'en',
        EntityInvitation::class,
        $this->game->id,
        'game_id',
        '/en/dashboard/games/'.$this->game->id,
    );
    $this->get($marker);

    $bell->call('refreshUnreadCount')
        ->assertSet('unreadCount', 1);

    // In-app half: marking the remaining group read takes the badge to 0 —
    // the full triangle: cross-channel + in-app both clear.
    $remaining = invitationRow($this->recipient, $otherGame->id);
    $groupKey = 'EntityInvitation_'.$remaining->created_at->toDateString();

    $bell->call('markAsRead', $groupKey)
        ->assertSet('unreadCount', 0);

    expect($this->recipient->unreadNotifications()->count())->toBe(0);
});
