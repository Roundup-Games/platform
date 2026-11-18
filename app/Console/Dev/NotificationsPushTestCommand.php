<?php

namespace App\Console\Dev;

use App\Dto\PushPayload;
use App\Enums\AttendanceStatus;
use App\Enums\BadgeSource;
use App\Enums\NotificationCategory;
use App\Models\AttendanceReport;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Models\User;
use App\Notifications\AttendanceNudge;
use App\Notifications\AttendanceReported;
use App\Notifications\AttendanceResolved;
use App\Notifications\BadgeAwardedNotification;
use App\Notifications\BaseNotification;
use App\Notifications\BelowMinPlayersWarning;
use App\Notifications\BulletinPosted;
use App\Notifications\Channels\PushChannel;
use App\Notifications\CharterAmended;
use App\Notifications\CharterPublished;
use App\Notifications\ConfirmationExpired;
use App\Notifications\DisputeResolved;
use App\Notifications\EntityCancelled;
use App\Notifications\EntityInvitation;
use App\Notifications\EventRegistrationConfirmed;
use App\Notifications\NewFollower;
use App\Notifications\PlayerBenched;
use App\Notifications\SeatDemoted;
use App\Notifications\SessionReminder;
use App\Notifications\WaitlistPlaced;
use App\Notifications\WaitlistPromoted;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Dev-only push test harness (M080/S02/T02).
 *
 * Delivers a representative push payload for any NotificationCategory to
 * the account named via --user, through the real PushChannel + WebPush
 * stack — this is what makes the real-device demo from the slice plan
 * executable: `notifications:push-test waitlist_promoted --user=you@…`
 * lands on the operator's own subscribed device.
 *
 * Payload provenance per category:
 *  - Where a real notification class defines toPush(), THAT class is
 *    constructed with small factory fixtures (recognizable "Push Test …"
 *    names) and its real payload is rendered — the device sees exactly
 *    what production would send.
 *  - Categories whose classes deliberately carry no push voice (D130:
 *    a channel's voice is written or consciously omitted) get the shared
 *    envelope fallback payload, so every category is still testable
 *    end-to-end. See NO_PUSH_VOICE.
 *
 * The payload is printed as JSON for copy-paste review and logged as a
 * structured push.test line before delivery is attempted. Delivery is
 * synchronous (direct PushChannel::send, no queue): the operator gets
 * per-device outcomes immediately via the push.sent / push.send_failed /
 * push.subscription_expired log lines PushChannel emits.
 *
 * No transport code is changed and no NotificationService preferences are
 * consulted — this is a synthetic single-recipient send.
 */
class NotificationsPushTestCommand extends Command
{
    /**
     * Categories whose notification classes deliberately carry no push
     * voice (D130: no toPush() on the class — a conscious channel opt-out,
     * not an oversight). The command delivers the shared-envelope fallback
     * payload for these, so every category remains testable on a real
     * device. A hygiene test pins this list against NotificationCategory
     * and against notificationFor(), so a new category fails loudly until
     * it is classified here consciously.
     *
     * @var array<string, string>
     */
    public const NO_PUSH_VOICE = [
        'team_invitation' => 'TeamInvitation has no toPush() (D130 opt-out)',
        'session_added_to_campaign' => 'SessionAddedToCampaign has no toPush() (D130 opt-out)',
        'new_application' => 'NewApplication has no toPush() (D130 opt-out)',
        'application_approved' => 'ApplicationApproved has no toPush() (D130 opt-out)',
        'application_rejected' => 'ApplicationRejected has no toPush() (D130 opt-out)',
        'participant_joined' => 'ParticipantJoined has no toPush() (D130 opt-out)',
        'participant_removed' => 'ParticipantRemoved has no toPush() (D130 opt-out)',
        'team_member_removed' => 'TeamMemberRemoved has no toPush() (D130 opt-out)',
        'game_completed' => 'EntityCompleted has no toPush() (D130 opt-out)',
        'campaign_completed' => 'EntityCompleted has no toPush() (D130 opt-out)',
        'game_updated' => 'EntityUpdated has no toPush() (D130 opt-out)',
        'campaign_updated' => 'EntityUpdated has no toPush() (D130 opt-out)',
        'game_system_request' => 'GameSystemRequest* classes have no toPush() (D130 opt-out, admin workflow)',
        'review_reported' => 'ReviewReported has no toPush() (D130 opt-out)',
        'moderation_notice' => 'ContentRemoved / ContentReportWarning have no toPush() (D130 opt-out)',
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:push-test
        {category : Notification category slug (a NotificationCategory value, e.g. waitlist_promoted)}
        {--user= : REQUIRED — email of the account that receives the test push (your own)}
        {--locale=en : Payload locale (en or de)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a representative push notification for a category to your own subscriptions (dev only)';

    /**
     * Lazily created fixture entities, shared across category builders.
     * Recognizable "Push Test …" names so the operator can spot them in
     * the payload copy; memoized so one invocation pays for one set.
     */
    private ?User $actor = null;

    private ?User $gameOwner = null;

    private ?Game $game = null;

    private ?Campaign $campaign = null;

    private ?GameBulletin $bulletin = null;

    private ?SessionZeroSurvey $charter = null;

    private ?SessionZeroSurveyVersion $charterVersion = null;

    private ?EventRegistration $eventRegistration = null;

    private ?AttendanceReport $attendanceReport = null;

    public function __construct(private PushChannel $pushChannel)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Registration-gated to non-production (AppServiceProvider), but
        // guard here too so direct instantiation can never fire a push
        // from a prod-adjacent box — the same defense-in-depth and guard
        // list demo:seed carries.
        if (app()->environment('production', 'prod', 'live', 'staging')) {
            $this->error('notifications:push-test is a development tool and is disabled in production.');

            return self::FAILURE;
        }

        // The recipient is the REQUIRED --user account: artisan has no
        // authenticated user, so the operator names their own — that is
        // what makes the real-device demo executable.
        $email = $this->option('user');
        if (! is_string($email) || $email === '') {
            $this->error('The --user option is required. Pass your own account email: notifications:push-test <category> --user=you@example.com');

            return self::FAILURE;
        }

        // Eager-load pushSubscriptions: PushChannel reads the relation, and
        // Model::shouldBeStrict in non-prod throws on lazy-loading a
        // long-lived model (the operator's account is never wasRecentlyCreated).
        $recipient = User::with('pushSubscriptions')->where('email', $email)->first();
        if ($recipient === null) {
            $this->error("No user found with email <{$email}>.");

            return self::FAILURE;
        }

        $slug = $this->argument('category');
        $category = NotificationCategory::tryFrom($slug);
        if ($category === null) {
            $this->error("Unknown category [{$slug}]. Valid categories:");
            $this->line(implode(', ', array_map(
                static fn (NotificationCategory $case): string => $case->value,
                NotificationCategory::cases(),
            )));

            return self::FAILURE;
        }

        $locale = $this->option('locale');
        $locale = is_string($locale) && $locale !== '' ? $locale : 'en';
        $availableLocales = config('app.available_locales', ['en']);
        $locales = ['en'];
        if (is_array($availableLocales)) {
            $locales = [];
            foreach ($availableLocales as $value) {
                if (is_string($value)) {
                    $locales[] = $value;
                }
            }
        }

        if (! in_array($locale, $locales, true)) {
            $this->error('Unsupported locale ['.$locale.']. Available: '.implode(', ', $locales));

            return self::FAILURE;
        }

        app()->setLocale($locale);

        // Build the representative payload: the real notification class
        // where one carries a push voice, otherwise the shared envelope.
        $notification = $this->notificationFor($category);

        $payload = null;
        if ($notification !== null && method_exists($notification, 'toPush')) {
            /** @var PushPayload|null $rendered */
            $rendered = $notification->toPush($recipient);
            $payload = $rendered;
        }

        $sendable = $notification;
        $fallback = false;

        if ($payload === null) {
            $payload = $this->fallbackPayload($category, $locale);
            $sendable = $this->envelopeNotification($payload);
            $fallback = true;
        }

        // Structured machine record of the test push, emitted before
        // delivery so even a refused send (no VAPID / no subscriptions)
        // leaves a trace.
        Log::info('push.test', [
            'category' => $category->value,
            'user_id' => $recipient->id,
            'notification' => $sendable::class,
            'fallback_envelope' => $fallback,
            'locale' => $locale,
            'subscriptions' => $recipient->pushSubscriptions->count(),
            'payload' => $payload->toArray(),
        ]);

        $this->info("Category: {$category->value} ({$category->label()})");
        $this->info('Notification: '.$sendable::class.($fallback ? ' — fallback envelope, the category carries no dedicated push voice (D130)' : ''));
        $this->info("Recipient: {$recipient->email} ({$recipient->pushSubscriptions->count()} subscription(s))");
        $this->newLine();

        $payloadJson = json_encode($payload->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->line($payloadJson === false ? '{}' : $payloadJson);
        $this->newLine();

        if (! $this->vapidConfigured()) {
            $this->error('VAPID keys are not configured (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY). The payload above was rendered and logged, but no push can be sent.');

            return self::FAILURE;
        }

        if ($recipient->pushSubscriptions->isEmpty()) {
            $this->error("{$recipient->email} has no push subscriptions. Enable push in the browser for this account first (notification settings → Enable push), then retry.");

            return self::FAILURE;
        }

        $this->pushChannel->send($recipient, $sendable);

        $this->info('Dispatch attempted to '.$recipient->pushSubscriptions->count().' subscription(s). Per-device outcomes are logged as push.sent / push.send_failed / push.subscription_expired.');

        return self::SUCCESS;
    }

    /**
     * Construct the real notification class for the category, where one
     * carries a push voice. Categories listed in NO_PUSH_VOICE return
     * null (D130: their classes deliberately define no toPush()).
     *
     * Public for the coverage hygiene test — not a supported API.
     */
    public function notificationFor(NotificationCategory $category): ?Notification
    {
        return match ($category) {
            NotificationCategory::NewFollower => new NewFollower($this->fixtureActor()),
            NotificationCategory::GameInvitation => new EntityInvitation($this->fixtureGame(), $this->fixtureActor()),
            NotificationCategory::CampaignInvitation => new EntityInvitation($this->fixtureCampaign(), $this->fixtureActor()),
            NotificationCategory::SeatDemoted => new SeatDemoted($this->fixtureGame(), 'attendance'),
            NotificationCategory::WaitlistPromoted => new WaitlistPromoted($this->fixtureGame(), Carbon::now()->addDays(2)),
            NotificationCategory::WaitlistPlacement => new WaitlistPlaced($this->fixtureGame()),
            NotificationCategory::BenchUpdates => new PlayerBenched($this->fixtureGame(), 'game'),
            NotificationCategory::AttendanceReported => new AttendanceReported($this->fixtureGame(), $this->fixtureAttendanceReport()),
            NotificationCategory::DisputeResolved => new DisputeResolved($this->fixtureGame(), 'resolved_favor'),
            NotificationCategory::AttendanceNudge => new AttendanceNudge($this->fixtureGame(), Carbon::now()->addHours(24)),
            NotificationCategory::AttendanceResolved => new AttendanceResolved($this->fixtureGame(), AttendanceStatus::NoShow),
            NotificationCategory::GameCancelled => new EntityCancelled($this->fixtureGame()),
            NotificationCategory::CampaignCancelled => new EntityCancelled($this->fixtureCampaign()),
            NotificationCategory::SessionContent => new BulletinPosted($this->fixtureGame(), $this->fixtureGameOwner(), $this->fixtureBulletin()),
            NotificationCategory::CharterPublished => new CharterPublished($this->fixtureCampaign(), $this->fixtureCharter()),
            NotificationCategory::CharterAmended => new CharterAmended($this->fixtureCampaign(), $this->fixtureCharter(), $this->fixtureCharterVersion(), true),
            NotificationCategory::EventRegistration => new EventRegistrationConfirmed($this->fixtureEventRegistration()),
            NotificationCategory::BelowMinPlayers => new BelowMinPlayersWarning($this->fixtureGame(), 1, 2),
            NotificationCategory::ConfirmationExpired => new ConfirmationExpired($this->fixtureGame()),
            NotificationCategory::SessionReminder => new SessionReminder($this->fixtureGame(), '1h'),
            NotificationCategory::BadgeAwarded => new BadgeAwardedNotification('supporting_member', null, BadgeSource::Automatic, false),

            // Deliberate no-push-voice categories — see NO_PUSH_VOICE.
            NotificationCategory::TeamInvitation,
            NotificationCategory::SessionAddedToCampaign,
            NotificationCategory::NewApplication,
            NotificationCategory::ApplicationApproved,
            NotificationCategory::ApplicationRejected,
            NotificationCategory::ParticipantJoined,
            NotificationCategory::ParticipantRemoved,
            NotificationCategory::TeamMemberRemoved,
            NotificationCategory::GameCompleted,
            NotificationCategory::CampaignCompleted,
            NotificationCategory::GameUpdated,
            NotificationCategory::CampaignUpdated,
            NotificationCategory::GameSystemRequest,
            NotificationCategory::ReviewReported,
            NotificationCategory::ModerationNotice => null,
        };
    }

    /**
     * Fallback payload for categories without a dedicated push voice:
     * the localized category label in the shared push envelope, so the
     * device delivery path is exercised end-to-end for every category.
     */
    private function fallbackPayload(NotificationCategory $category, string $locale): PushPayload
    {
        return new PushPayload(
            title: $category->label(),
            body: "Representative push for category '{$category->value}' — this category carries no dedicated push voice (D130 deliberate opt-out), so it is delivered with the shared envelope to exercise the device path.",
            icon: BaseNotification::PUSH_ICON,
            url: route('home', ['locale' => $locale]),
            tag: "push-test-{$category->value}",
        );
    }

    /**
     * Minimal sendable notification wrapping a pre-built payload — the
     * vehicle for fallback sends, since PushChannel re-derives the payload
     * from the notification via toPush().
     */
    private function envelopeNotification(PushPayload $payload): Notification
    {
        return new class($payload) extends Notification
        {
            public function __construct(private PushPayload $payload) {}

            /**
             * @return array<int, string>
             */
            public function via(object $notifiable): array
            {
                return [];
            }

            public function toPush(object $notifiable): PushPayload
            {
                return $this->payload;
            }
        };
    }

    private function vapidConfigured(): bool
    {
        $publicKey = config('services.vapid.public_key');
        $privateKey = config('services.vapid.private_key');

        return is_string($publicKey) && $publicKey !== ''
            && is_string($privateKey) && $privateKey !== '';
    }

    // ── Fixture entities ───────────────────────────────────────────────
    // Small factory fixtures with recognizable names. Created lazily and
    // memoized; only the clusters the chosen category needs are built.
    // Never attached to the operator's own records.

    private function fixtureActor(): User
    {
        return $this->actor ??= User::factory()->create([
            'name' => 'Push Test Actor',
            'profile_complete' => true,
        ]);
    }

    private function fixtureGameOwner(): User
    {
        return $this->gameOwner ??= User::factory()->create([
            'name' => 'Push Test Owner',
            'profile_complete' => true,
        ]);
    }

    private function fixtureGame(): Game
    {
        return $this->game ??= Game::factory()->create([
            'owner_id' => $this->fixtureGameOwner()->id,
            'name' => ['en' => 'Push Test Game', 'de' => 'Push-Test-Spiel'],
        ]);
    }

    private function fixtureCampaign(): Campaign
    {
        return $this->campaign ??= Campaign::factory()->create([
            'owner_id' => $this->fixtureGameOwner()->id,
            'name' => ['en' => 'Push Test Campaign', 'de' => 'Push-Test-Kampagne'],
        ]);
    }

    private function fixtureBulletin(): GameBulletin
    {
        return $this->bulletin ??= GameBulletin::factory()->create([
            'game_id' => $this->fixtureGame()->id,
            'user_id' => $this->fixtureGameOwner()->id,
        ]);
    }

    private function fixtureCharter(): SessionZeroSurvey
    {
        return $this->charter ??= SessionZeroSurvey::factory()->charter()->create([
            'campaign_id' => $this->fixtureCampaign()->id,
        ]);
    }

    private function fixtureCharterVersion(): SessionZeroSurveyVersion
    {
        return $this->charterVersion ??= SessionZeroSurveyVersion::factory()->forSurvey($this->fixtureCharter())->create();
    }

    private function fixtureEventRegistration(): EventRegistration
    {
        return $this->eventRegistration ??= EventRegistration::factory()->confirmed()->create([
            'event_id' => Event::factory()->create(['organizer_id' => $this->fixtureActor()->id])->id,
            'user_id' => $this->fixtureActor()->id,
        ]);
    }

    private function fixtureAttendanceReport(): AttendanceReport
    {
        return $this->attendanceReport ??= AttendanceReport::factory()->create([
            'game_id' => $this->fixtureGame()->id,
            'reporter_id' => $this->fixtureActor()->id,
            'reported_id' => $this->fixtureGameOwner()->id,
        ]);
    }
}
