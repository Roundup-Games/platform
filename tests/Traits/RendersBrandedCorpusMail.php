<?php

namespace Tests\Traits;

use App\Enums\NotificationCategory;
use App\Mail\EntityInvitationEmail;
use App\Mail\WelcomeEmail;
use App\Models\GameBulletin;
use App\Models\User;
use App\Notifications\BaseNotification;
use App\Notifications\BatchedNotification;
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
                // Just-in-time dating (D292): the fixture session stays
                // ahead of the wall clock — a fixed date would age past
                // every future-facing gate and render as a past session.
                now()->addDays(16)->setTime(20, 0),
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
            'BatchedNotification' => new BatchedNotification(
                NotificationCategory::ParticipantJoined,
                'game',
                $fixture->game->id,
                $fixture->game->name,
                url('/games/'.$fixture->game->id),
                $this->batchedCorpusItems($fixture),
            ),
            default => throw new InvalidArgumentException("Unknown branded corpus notification [{$class}]."),
        };
    }

    /**
     * Buffered-item snapshots for the batched corpus arm, following the
     * payload contract in BatchedNotification's docblock: five activities
     * across three distinct actors (the headline mail tier and the compact
     * push actor prefix), dated relative to now per D292 just-in-time
     * dating — past-direction instants keep the digest deterministic and
     * can never age past a future-facing gate.
     *
     * @param  object  $fixture  Lazy fixture context from brandingFixture()
     * @return array<int, array<string, mixed>>
     */
    protected function batchedCorpusItems(object $fixture): array
    {
        $actors = [
            ['id' => $fixture->actor->id, 'name' => $fixture->actor->name],
            ['id' => $fixture->gameOwner->id, 'name' => $fixture->gameOwner->name],
            ['id' => $fixture->recipient->id, 'name' => $fixture->recipient->name],
        ];

        return collect(range(0, 4))
            ->map(fn (int $i): array => [
                'actor_id' => $actors[$i % 3]['id'],
                'actor_name' => $actors[$i % 3]['name'],
                'entity_type' => 'game',
                'entity_id' => $fixture->game->id,
                'entity_name' => $fixture->game->name,
                'event' => 'participant_joined',
                'context' => [],
                'action_url' => url('/games/'.$fixture->game->id),
                'occurred_at' => now()->subMinutes(5 - $i)->toIso8601String(),
            ])
            ->all();
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
