<?php

namespace Tests\Traits;

use App\Mail\EntityInvitationEmail;
use App\Mail\WelcomeEmail;
use App\Models\GameBulletin;
use App\Models\User;
use App\Notifications\BaseNotification;
use App\Notifications\EntityInvitation;
use App\Notifications\WaitlistPromoted;
use Carbon\Carbon;
use Closure;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/*
|--------------------------------------------------------------------------
| Branded corpus mail harness (M081/S01/T01)
|--------------------------------------------------------------------------
|
| Renders the representative mail corpus subset (four notification classes
| + two app/Mail mailables) through the REAL send surfaces on the array
| mailer: notifications go through the container's MailChannel binding
| (TaggedMailChannel), mailables through Mail::to()->send() — so the
| captured bodies are exactly what the transport would receive, including
| the theme the Markdown renderer inlined. Fixture arms mirror the
| NotificationCorpusRenderTest builders for the same classes.
|
| Returns [html, text] pairs; callers assert on branding tokens, the
| dark-mode block, and the text part.
|
*/

trait RendersBrandedCorpusMail
{
    use CreatesGameInstances;

    /** @var object|null lazy fixture context shared by all corpus arms */
    protected $brandingFixture = null;

    /**
     * Lazy fixture context (built once per test): recipient/actor users,
     * a real game + owner, and a lazily-created bulletin.
     */
    protected function brandingFixture(): object
    {
        if ($this->brandingFixture !== null) {
            return $this->brandingFixture;
        }

        $gameContext = $this->createGameWithOwner();

        return $this->brandingFixture = (object) [
            'recipient' => User::factory()->create(['profile_complete' => true]),
            'actor' => User::factory()->create(['profile_complete' => true]),
            'game' => $gameContext['game'],
            'gameOwner' => $gameContext['owner'],
            'bulletin' => null,
        ];
    }

    /**
     * Render a corpus mail case ('notification/<Class>' or
     * 'mailable/<Class>') in the given locale and return the captured
     * transport bodies.
     *
     * @return array{html: string, text: string}
     */
    protected function sendBrandedCorpusMail(string $case, string $locale): array
    {
        app()->setLocale($locale);

        [$kind, $class] = explode('/', $case, 2);

        return $kind === 'notification'
            ? $this->sendBrandedNotificationMail($class)
            : $this->sendBrandedMailableMail($class);
    }

    /**
     * @return array{html: string, text: string}
     */
    protected function sendBrandedNotificationMail(string $class): array
    {
        $fixture = $this->brandingFixture();
        $notification = $this->buildBrandingNotification($class);

        return $this->captureMail(
            fn () => app(MailChannel::class)->send($fixture->recipient, $notification)
        );
    }

    /**
     * @return array{html: string, text: string}
     */
    protected function sendBrandedMailableMail(string $class): array
    {
        $fixture = $this->brandingFixture();

        $mailable = match ($class) {
            'WelcomeEmail' => new WelcomeEmail($fixture->recipient),
            'EntityInvitationEmail' => new EntityInvitationEmail(
                'game',
                'Branding Corpus Game',
                Carbon::parse('2026-12-04 20:00:00'),
                'Community Café',
                'Sarah',
                // Non-RFC-2606 domain: the envelope recipient comes from this
                // constructor arg, and DropDemoDomainMail halts example.com
                // sends before the capture sees them.
                'invitee@branding-corpus.test',
                'https://example.com/signup',
            ),
            default => throw new InvalidArgumentException("Unknown branded corpus mailable [{$class}]."),
        };

        // Non-RFC-2606 domain: DropDemoDomainMail halts example.com/.net/.org
        // sends, which would silently empty the capture.
        return $this->captureMail(
            fn () => Mail::to('invitee@branding-corpus.test')->send($mailable)
        );
    }

    /**
     * Notification fixture builders for the corpus subset (mirrors the
     * NotificationCorpusRenderTest arms for the same classes).
     */
    protected function buildBrandingNotification(string $class): BaseNotification
    {
        $fixture = $this->brandingFixture();
        $fqcn = 'App\\Notifications\\'.$class;

        return match ($class) {
            'WaitlistPromoted' => new $fqcn($fixture->game, Carbon::now()->addDays(2)),
            'EntityCancelled' => new $fqcn($fixture->game),
            'BulletinPosted' => new $fqcn(
                $fixture->game,
                $fixture->gameOwner,
                $fixture->bulletin ??= GameBulletin::factory()->create([
                    'game_id' => $fixture->game->id,
                    'user_id' => $fixture->gameOwner->id,
                ]),
            ),
            'WeeklyDigest' => (function () use ($fqcn, $fixture): BaseNotification {
                $fixture->recipient->notifications()->createMany([
                    ['id' => Str::uuid(), 'type' => EntityInvitation::class, 'data' => ['type' => 'game_invitation']],
                    ['id' => Str::uuid(), 'type' => WaitlistPromoted::class, 'data' => ['type' => 'waitlist_promoted']],
                ]);

                return $fqcn::fromNotifications($fixture->recipient->unreadNotifications);
            })(),
            default => throw new InvalidArgumentException("Unknown branded corpus notification [{$class}]."),
        };
    }

    /**
     * Run a send closure and return the HTML/text bodies of the mail it
     * dispatched. Capture runs through the MessageSending event (the
     * suite's proven capture surface — MailCategoryTagTest uses the same
     * listener), independent of which mailer/transport the send resolved
     * to.
     *
     * @return array{html: string, text: string}
     */
    protected function captureMail(Closure $send): array
    {
        $sent = [];

        Event::listen(MessageSending::class, function (MessageSending $event) use (&$sent): void {
            $sent[] = $event->message;
        });

        $send();

        test()->assertNotEmpty($sent, 'No message captured via MessageSending.');

        /** @var Email $email */
        $email = $sent[array_key_last($sent)];

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();

        test()->assertIsString($html, 'Captured mail has no HTML body.');
        test()->assertIsString($text, 'Captured mail has no text body.');

        return ['html' => (string) $html, 'text' => (string) $text];
    }
}
