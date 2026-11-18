<?php

use App\Enums\NotificationCategory;
use App\Models\Game;
use App\Models\User;
use App\Notifications\Channels\TaggedMailChannel;
use App\Notifications\WaitlistPromoted;
use App\Notifications\WeeklyDigest;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Header\MetadataHeader;

// ══════════════════════════════════════════════════════
// TaggedMailChannel (M080/S03, D284) — per-send mail
// attribution.
//
// The container binding in AppServiceProvider::register resolves the
// framework mail channel as TaggedMailChannel, whose buildMessage()
// stamps a roundup_category MetadataHeader on every notification mail.
// resend/resend-laravel forwards that header into the Resend API send's
// tags array, and Resend's engagement webhooks echo it back at
// data.tags for event attribution (see the channel's PHPDoc).
//
// Sends run through the REAL dispatch surface (NotificationService +
// sync queue) on the array mailer (phpunit.xml MAIL_MAILER=array) —
// never a live Resend send (D283).
// ══════════════════════════════════════════════════════

/**
 * Notification with no toDatabase() category payload — pins the
 * class-basename fallback path (framework auth mails like VerifyEmail
 * and ResetPassword take it).
 */
final class UntaggedProbeNotification extends Notification
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
            ->subject('Untagged probe')
            ->line('No category payload.');
    }
}

/**
 * toDatabase() exists but its payload carries no category `type` — the
 * guard must fall back to the basename instead of tagging with a null
 * or malformed value (what Resend would reject or misattribute).
 */
final class TypelessDatabaseProbeNotification extends Notification
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

beforeEach(function () {
    // Capture the Symfony message each notification mail produces.
    $this->capturedMails = collect();
    Event::listen(MessageSending::class, function (MessageSending $event): void {
        $this->capturedMails->push($event->message);
    });
});

it('stamps the roundup_category metadata header on a dispatched notification mail', function (string $locale) {
    $user = User::factory()->create(['preferred_language' => $locale]);
    $game = Game::factory()->create();

    app(NotificationService::class)->send(
        $user,
        new WaitlistPromoted($game, Carbon::parse('next friday 18:00')),
        NotificationCategory::WaitlistPromoted,
    );

    expect($this->capturedMails)->toHaveCount(1)
        ->and($this->capturedMails[0]->getHeaders()->get('X-Metadata-'.TaggedMailChannel::CATEGORY_TAG))
        ->toBeInstanceOf(MetadataHeader::class)
        ->getKey()->toBe(TaggedMailChannel::CATEGORY_TAG)
        ->and($this->capturedMails[0]->getHeaders()->get('X-Metadata-'.TaggedMailChannel::CATEGORY_TAG)->getValue())
        ->toBe('waitlist_promoted');
})->with(['en', 'de']);

it('localizes the mail copy while the category tag stays constant', function () {
    $subjects = collect(['en', 'de'])->mapWithKeys(function (string $locale) {
        $user = User::factory()->create(['preferred_language' => $locale]);
        $game = Game::factory()->create();

        $this->capturedMails = collect();
        app(NotificationService::class)->send(
            $user,
            new WaitlistPromoted($game, Carbon::parse('next friday 18:00')),
            NotificationCategory::WaitlistPromoted,
        );

        $header = $this->capturedMails[0]->getHeaders()->get('X-Metadata-'.TaggedMailChannel::CATEGORY_TAG);

        return [
            $locale => [
                'subject' => $this->capturedMails[0]->getSubject(),
                'tag' => $header?->getValue(),
            ],
        ];
    });

    // The mail itself localizes (recipient's preferred locale via
    // HasLocalePreference), but the attribution tag is locale-independent.
    expect($subjects['en']['subject'])->not->toBe($subjects['de']['subject'])
        ->and($subjects['en']['tag'])->toBe('waitlist_promoted')
        ->and($subjects['de']['tag'])->toBe('waitlist_promoted');
});

it('falls back to the notification class basename when no category payload exists', function () {
    $user = User::factory()->create();

    $user->notify(new UntaggedProbeNotification);

    $header = $this->capturedMails[0]->getHeaders()->get('X-Metadata-'.TaggedMailChannel::CATEGORY_TAG);

    expect($this->capturedMails)->toHaveCount(1)
        ->and($header)->toBeInstanceOf(MetadataHeader::class)
        ->and($header->getValue())->toBe('UntaggedProbeNotification');
});

it('falls back to the class basename when the database payload carries no type', function () {
    $user = User::factory()->create();

    $user->notify(new TypelessDatabaseProbeNotification);

    $header = $this->capturedMails[0]->getHeaders()->get('X-Metadata-'.TaggedMailChannel::CATEGORY_TAG);

    expect($this->capturedMails)->toHaveCount(1)
        ->and($header)->toBeInstanceOf(MetadataHeader::class)
        ->and($header->getValue())->toBe('TypelessDatabaseProbeNotification');
});

it('tags the weekly digest mail with the canonical digest category', function () {
    // The D284 delivered-vs-unsubscribed funnel join (800a3b7c):
    // unsubscribeDigest() counts opt-outs under 'digest', so the SEND side
    // must stamp the same key — if toDatabase()'s type changed or the digest
    // ever left the TaggedMailChannel path, delivered counters would fall
    // back to the 'WeeklyDigest' basename and the funnel would split with
    // zero other failures. Production dispatch is $user->notify(
    // WeeklyDigest::fromNotifications($user->unreadNotifications))
    // (SendWeeklyDigest command).
    $user = User::factory()->create(['weekly_digest_enabled' => true]);
    $user->notifications()->create([
        'id' => Str::uuid(),
        'type' => WaitlistPromoted::class,
        'data' => ['type' => 'waitlist_promoted'],
    ]);

    $user->notify(WeeklyDigest::fromNotifications($user->unreadNotifications));

    expect($this->capturedMails)->toHaveCount(1)
        ->and($this->capturedMails[0]->getHeaders()->get('X-Metadata-'.TaggedMailChannel::CATEGORY_TAG)?->getValue())
        ->toBe('digest');
});
