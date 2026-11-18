<?php

use App\Enums\NotificationCategory;
use App\Models\Game;
use App\Models\User;
use App\Notifications\WaitlistPromoted;
use App\Notifications\WeeklyDigest;
use App\Services\Mail\SignedUnsubscribeUrls;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Header\UnstructuredHeader;

// ══════════════════════════════════════════════════════
// List-Unsubscribe trust headers (M081/S02/T02, RFC 2369
// / RFC 8058) at the TaggedMailChannel chokepoint.
//
// Every CATEGORY mail carries List-Unsubscribe (signed
// one-click https URL + mailto fallback) and List-Unsubscribe-Post:
// List-Unsubscribe=One-Click. The https URL must be the
// SignedUnsubscribeUrls generator's output — the same URL the
// mail body's unsubscribe line carries and the RFC 8058 POST
// (T01) validates. Transactional mail (basename-fallback
// categories: framework VerifyEmail/ResetPassword shapes)
// gets neither header. Tamper-shaped category values can
// never inject CR/LF: enum/digest validation gates stamping,
// and the assembled value is stripped defensively.
//
// Sends run through the REAL dispatch surface
// (NotificationService + sync queue, or direct notify() for
// probe shapes) on the array mailer (phpunit.xml
// MAIL_MAILER=array) — never a live Resend send (D283).
// ══════════════════════════════════════════════════════

/**
 * Framework-style notification with no toDatabase() category
 * payload — pins the class-basename fallback skip rule (real
 * world: VerifyEmail, ResetPassword — transactional mail that
 * must carry no unsubscribe headers).
 */
final class FrameworkStyleProbeNotification extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return [MailChannel::class];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Framework-style probe')
            ->line('No category payload.');
    }
}

/**
 * toDatabase() exists but carries no category `type` — same
 * basename-fallback skip rule, second entry path.
 */
final class TypelessProbeNotification extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return [MailChannel::class];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Typeless probe')
            ->line('Database payload without a type.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        return ['entity_id' => 1];
    }
}

/**
 * toDatabase() `type` shaped like a header-injection payload —
 * a tampered category must never smuggle CR/LF (or an extra
 * header) into the outgoing message via the unsubscribe path.
 */
final class TamperedTypeProbeNotification extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return [MailChannel::class];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tampered probe')
            ->line('Hostile category payload.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        return ['type' => "waitlist_promoted\r\nBcc: evil@evil.example"];
    }
}

/**
 * Expected full List-Unsubscribe value for a given URL and
 * category: the signed https one-click entry plus the
 * configured mailto fallback with the percent-encoded category
 * token in the subject.
 */
function expectedUnsubscribeValue(string $url, string $category): string
{
    $mailto = (string) config('mail.unsubscribe_mailto');

    if (! str_starts_with($mailto, 'mailto:')) {
        $mailto = 'mailto:'.$mailto;
    }

    return '<'.$url.'>, <'.$mailto.'?subject='.rawurlencode('Unsubscribe: '.$category).'>';
}

beforeEach(function () {
    // Capture the Symfony message each notification mail produces.
    $this->capturedMails = collect();
    Event::listen(MessageSending::class, function (MessageSending $event): void {
        $this->capturedMails->push($event->message);
    });
});

it('stamps List-Unsubscribe with the shared signed category URL and List-Unsubscribe-Post on category mail', function (string $locale) {
    $user = User::factory()->create(['preferred_language' => $locale]);
    $game = Game::factory()->create();

    app(NotificationService::class)->send(
        $user,
        new WaitlistPromoted($game, Carbon::parse('next friday 18:00')),
        NotificationCategory::WaitlistPromoted,
    );

    $expectedUrl = app(SignedUnsubscribeUrls::class)->forCategory(
        $user,
        $locale,
        NotificationCategory::WaitlistPromoted->value,
    );

    $header = $this->capturedMails[0]->getHeaders()->get('List-Unsubscribe');
    $post = $this->capturedMails[0]->getHeaders()->get('List-Unsubscribe-Post');

    expect($this->capturedMails)->toHaveCount(1)
        ->and($header)->toBeInstanceOf(UnstructuredHeader::class)
        ->and($header->getValue())->toBe(expectedUnsubscribeValue($expectedUrl, 'waitlist_promoted'))
        ->and($header->getValue())->not->toContain("\r")
        ->and($header->getValue())->not->toContain("\n")
        ->and($post)->toBeInstanceOf(UnstructuredHeader::class)
        ->and($post->getValue())->toBe('List-Unsubscribe=One-Click');
})->with(['en', 'de']);

it('stamps the digest URL on the weekly digest mail', function () {
    $user = User::factory()->create(['preferred_language' => 'de', 'weekly_digest_enabled' => true]);
    $user->notifications()->create([
        'id' => Str::uuid(),
        'type' => WaitlistPromoted::class,
        'data' => ['type' => 'waitlist_promoted'],
    ]);

    $user->notify(WeeklyDigest::fromNotifications($user->unreadNotifications));

    $expectedUrl = app(SignedUnsubscribeUrls::class)->forDigest($user, 'de');

    $header = $this->capturedMails[0]->getHeaders()->get('List-Unsubscribe');

    expect($this->capturedMails)->toHaveCount(1)
        ->and($header)->toBeInstanceOf(UnstructuredHeader::class)
        ->and($header->getValue())->toBe(expectedUnsubscribeValue($expectedUrl, 'digest'))
        ->and($this->capturedMails[0]->getHeaders()->get('List-Unsubscribe-Post')->getValue())
        ->toBe('List-Unsubscribe=One-Click');
});

it('honors the mail.unsubscribe_mailto config override for the mailto fallback entry', function () {
    config(['mail.unsubscribe_mailto' => 'leave@roundup.example']);

    $user = User::factory()->create(['preferred_language' => 'en']);
    $game = Game::factory()->create();

    app(NotificationService::class)->send(
        $user,
        new WaitlistPromoted($game, Carbon::parse('next friday 18:00')),
        NotificationCategory::WaitlistPromoted,
    );

    $expectedUrl = app(SignedUnsubscribeUrls::class)->forCategory($user, 'en', 'waitlist_promoted');

    expect($this->capturedMails[0]->getHeaders()->get('List-Unsubscribe')->getValue())
        ->toBe(expectedUnsubscribeValue($expectedUrl, 'waitlist_promoted'));
});

it('stamps no unsubscribe headers on basename-fallback framework-style mail', function () {
    $user = User::factory()->create();

    $user->notify(new FrameworkStyleProbeNotification);

    expect($this->capturedMails)->toHaveCount(1)
        ->and($this->capturedMails[0]->getHeaders()->get('List-Unsubscribe'))->toBeNull()
        ->and($this->capturedMails[0]->getHeaders()->get('List-Unsubscribe-Post'))->toBeNull();
});

it('stamps no unsubscribe headers when the database payload carries no type', function () {
    $user = User::factory()->create();

    $user->notify(new TypelessProbeNotification);

    expect($this->capturedMails)->toHaveCount(1)
        ->and($this->capturedMails[0]->getHeaders()->get('List-Unsubscribe'))->toBeNull()
        ->and($this->capturedMails[0]->getHeaders()->get('List-Unsubscribe-Post'))->toBeNull();
});

it('rejects tamper-shaped category values without injecting headers or line breaks', function () {
    $user = User::factory()->create();

    $user->notify(new TamperedTypeProbeNotification);

    // The CR/LF-laced category fails enum/digest validation, so no
    // List-Unsubscribe header is stamped at all — there is no header
    // line for the payload to ride, and no smuggled Bcc appears.
    expect($this->capturedMails)->toHaveCount(1)
        ->and($this->capturedMails[0]->getHeaders()->get('List-Unsubscribe'))->toBeNull()
        ->and($this->capturedMails[0]->getHeaders()->get('List-Unsubscribe-Post'))->toBeNull()
        ->and($this->capturedMails[0]->getHeaders()->get('Bcc'))->toBeNull();
});
