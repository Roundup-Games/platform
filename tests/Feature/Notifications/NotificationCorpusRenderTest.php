<?php

namespace Tests\Feature\Notifications;

use App\Enums\AttendanceStatus;
use App\Enums\BadgeSource;
use App\Enums\NotificationCategory;
use App\Models\AttendanceReport;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\EventRegistration;
use App\Models\GameBulletin;
use App\Models\GameSystem;
use App\Models\Review;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Models\User;
use App\Notifications\BadgeAwardedNotification;
use App\Notifications\BaseNotification;
use App\Notifications\ContentRemoved;
use App\Notifications\EntityInvitation;
use App\Notifications\WaitlistPromoted;
use Carbon\Carbon;
use Database\Seeders\EscalatedSetupSeeder;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\CreatesGameInstances;
use Tests\Traits\CreatesTeams;

/*
|--------------------------------------------------------------------------
| Notification corpus render harness (M080/S02/T01)
|--------------------------------------------------------------------------
|
| Renders EVERY concrete BaseNotification subclass (App\Notifications\*,
| excluding the RoutesCharter/RoutesGameOrCampaign traits and the
| Channels\ channel classes) on every channel method it DEFINES, in both
| en and de locales. This is the machine backbone behind the Mailpit
| review flow in docs/notification-verification.md: the corpus loop makes
| the human review a checklist instead of spelunking, and it is the
| regression net M081's theme work will lean on.
|
| Channel gating: every channel method is guarded with method_exists()
| BEFORE it is called — SessionReminder has no toMail() (its own docblock
| says database/push/discord only), so an ungated call fatals the corpus
| loop mid-run. The same conditional applies to toPush()/toDiscord().
|
| Null returns from toPush()/toDiscord() are deliberate opt-outs
| (D130/D247: null = this channel's voice was consciously not written),
| not render failures — only non-null payloads are asserted.
|
| The corpus registry below is deliberately explicit: a completeness test
| reflects over app/Notifications and fails when a new notification class
| is added without being registered here. Future exceptions to the signed
| unsubscribe-line assertion must be added to corpusUnsubscribeExclusions()
| consciously, each with a one-line reason — never blanket-asserted.
*/

uses(CreatesGameInstances::class, CreatesTeams::class);

// ── Corpus registry ───────────────────────────────────────────────────────

/**
 * Short class name of every corpus-renderable notification, keyed by itself
 * so Pest dataset labels name the class under test.
 *
 * @return array<string, array{string}>
 */
function corpusNotificationDataset(): array
{
    $classes = [
        'AccountSuspended', 'ApplicationApproved', 'ApplicationRejected',
        'AttendanceNudge', 'AttendanceReported', 'AttendanceResolved',
        'BadgeAwardedNotification', 'BatchedNotification', 'BelowMinPlayersWarning',
        'BulletinPosted',
        'CharterAmended', 'CharterPublished', 'ConfirmationExpired',
        'ContentRemoved', 'ContentReportWarning', 'DebriefingAvailable',
        'DisputeResolved', 'EntityCancelled', 'EntityCompleted',
        'EntityInvitation', 'EntityUpdated', 'EventAnnouncementPublished',
        'EventCancelled', 'EventCoOrganizerAdded', 'EventRegistrationConfirmed',
        'GameSystemRequestApproved', 'GameSystemRequestDuplicate',
        'GameSystemRequestRejected', 'NewApplication', 'NewFollower',
        'ParticipantJoined', 'ParticipantRemoved', 'PlayerBenched',
        'PromotedFromBench', 'RecapPosted', 'ReviewReported', 'SeatDemoted',
        'SessionAddedToCampaign', 'SessionReminder', 'TeamInvitation',
        'TeamMemberRemoved', 'WaitlistExpiredRejected', 'WaitlistPlaced',
        'WaitlistPromoted', 'WeeklyDigest',
    ];

    return array_combine($classes, array_map(fn (string $c): array => [$c], $classes));
}

/**
 * Notification classes legitimately lacking the signed unsubscribe line
 * ('signature=') in their rendered mail, each with a one-line reason.
 *
 * An empty list is the goal. Every entry is a consciously reviewed,
 * legitimate exception; unknown future exceptions must be added here
 * deliberately — never by weakening the assertion itself.
 *
 * @return array<string, string>
 */
function corpusUnsubscribeExclusions(): array
{
    return [
        'AccountSuspended' => 'One-off account-suspension system notice: no body unsubscribe line by design (administrative one-off); delivery is still governed by the moderation_notice preference row.',
        'ContentRemoved' => 'Moderation takedown notice (admin to user): no body unsubscribe line by design (administrative one-off); delivery is still governed by the moderation_notice preference row.',
        'ContentReportWarning' => 'Moderation warning (user to admins): no body unsubscribe line by design (admin-workflow one-off); delivery is still governed by the moderation_notice preference row.',
        'ReviewReported' => 'Acknowledgement of the recipient\'s own abuse report: admin-workflow mail, no category subscription involved.',
    ];
}

/**
 * Corpus classes whose rendered mail legitimately carries NO signed
 * read-marker CTA (D288 item 1, M081/S08), each with a one-line reason.
 *
 * Two shapes of exception exist: WeeklyDigest is mail-only (its
 * supportedChannels() has no database channel — no bell row to clear), and
 * the one-off system/moderation notices whose toMail() deliberately has no
 * ->action() CTA at all (nothing to wire). Every other corpus mail CTA must
 * route through BaseNotification::actionUrlWithMarker() — a class gaining a
 * CTA or losing the wrap fails the corpus loop below.
 *
 * @return array<string, string>
 */
function corpusReadMarkerExclusions(): array
{
    return [
        'WeeklyDigest' => 'Mail-only by supportedChannels(): no database sibling row, so no marker to clear.',
        'AccountSuspended' => 'One-off suspension notice; toMail() carries no action CTA.',
        'ContentRemoved' => 'Moderation takedown notice; toMail() carries no action CTA.',
        'ContentReportWarning' => 'Moderation warning to admins; toMail() carries no action CTA.',
        'GameSystemRequestRejected' => 'Rejection notice; toMail() carries no action CTA.',
        'ReviewReported' => 'Abuse-report acknowledgement; toMail() carries no action CTA.',
    ];
}

/**
 * toDatabase()['type'] values that are deliberately NOT NotificationCategory
 * cases, each with a one-line reason. This is the conscious half of the
 * counter-vocabulary contract (D284): mail/push/discord counters attribute on
 * the type vocabulary (cross-channel comparable), while unsubscribe links and
 * preference gating use the NotificationCategory vocabulary — the two differ
 * for these types, and the funnel (delivered vs unsubscribed) joins per type
 * only where the type IS an enum value.
 *
 * These strings are load-bearing beyond the counters: the weekly digest
 * groups rows by data.type and looks up its label_digest_{type} key, and
 * stored in-app notification payloads carry them — renaming one to an enum
 * value would orphan that data. Changing this list requires a deliberate
 * vocabulary migration, not a refactor.
 *
 * Pinned by the corpus loop (fixture-produced types must be enum-valid or
 * listed here), the dynamic-variant test below (types the corpus fixtures
 * cannot reach), and the list-hygiene test (entries must stay non-enum and
 * carry a reason).
 *
 * @return array<string, string>
 */
function corpusTypeOnlyCategories(): array
{
    return [
        'account_suspended' => 'AccountSuspended: moderation_notice preference family (dispatched under NotificationCategory::ModerationNotice); no in-mail unsubscribe line (administrative one-off — see corpusUnsubscribeExclusions).',
        'badge_upgraded' => 'BadgeAwardedNotification (upgraded): distinct digest label from badge_awarded.',
        'bulletin_posted' => 'BulletinPosted: session_content preference family; distinct digest label.',
        'content_removed' => 'ContentRemoved: moderation_notice preference family (dispatched under ModerationNotice); no in-mail unsubscribe line (moderation takedown — see corpusUnsubscribeExclusions).',
        'cover_image_removed' => 'ContentRemoved (scope=cover_image): artifact-specific variant of content_removed; moderation_notice family.',
        'content_report_warning' => 'ContentReportWarning: moderation_notice preference family (dispatched under ModerationNotice); no in-mail unsubscribe line (admin-workflow mail).',
        'debriefing_available' => 'DebriefingAvailable: session_content preference family; distinct digest label.',
        'game_system_request_approved' => 'GameSystemRequestApproved: game_system_request preference family; distinct digest label.',
        'game_system_request_duplicate' => 'GameSystemRequestDuplicate: game_system_request preference family; distinct digest label.',
        'game_system_request_rejected' => 'GameSystemRequestRejected: game_system_request preference family; distinct digest label.',
        'player_benched' => 'PlayerBenched: bench_updates preference family; distinct digest label.',
        'promoted_from_bench' => 'PromotedFromBench: bench_updates preference family; distinct digest label.',
        'recap_posted' => 'RecapPosted: session_content preference family; distinct digest label.',
        'waitlist_expired_rejected' => 'WaitlistExpiredRejected: confirmation_expired preference family; distinct digest label.',
        'waitlist_placed' => 'WaitlistPlaced: waitlist_placement preference family; distinct digest label.',
        'digest' => 'WeeklyDigest category identity (not a NotificationCategory case): joins the delivered-vs-unsubscribed funnel on one key.',
    ];
}

// ── Fixtures ──────────────────────────────────────────────────────────────

beforeEach(function () {
    app()->setLocale('en');

    $gameContext = $this->createGameWithOwner();
    $campaignContext = $this->createCampaignWithOwner();

    // Shared fixture context. Cluster-specific entities (bulletin, charter,
    // event, ticket, review, team, session) are created lazily inside the
    // builder arms so a corpus case only pays for what it renders.
    // Recipient has preferred_language = null (factory default), so
    // resolveLocale() falls back to app()->setLocale() per K002 — the
    // locale loop below is what drives en/de rendering.
    $this->fx = (object) [
        'recipient' => User::factory()->create(['profile_complete' => true]),
        'actor' => User::factory()->create(['profile_complete' => true]),
        'game' => $gameContext['game'],
        'gameOwner' => $gameContext['owner'],
        'campaign' => $campaignContext['campaign'],
        'bulletin' => null,
        'charter' => null,
        'charterVersion' => null,
        'event' => null,
        'announcement' => null,
        'registration' => null,
        'ticket' => null,
        'gameSystem' => null,
        'review' => null,
        'team' => null,
        'session' => null,
    ];
});

// ── Corpus render ─────────────────────────────────────────────────────────

it('renders every defined channel with non-empty payloads in both en and de', function (string $class) {
    $make = function () use ($class): BaseNotification {
        $fx = $this->fx;
        $fqcn = 'App\\Notifications\\'.$class;

        return match ($class) {
            'AccountSuspended' => new $fqcn('inappropriate content'),
            'ApplicationApproved' => new $fqcn($fx->game, 'game', $fx->actor),
            'ApplicationRejected' => new $fqcn($fx->game, 'game', $fx->actor),
            'AttendanceNudge' => new $fqcn($fx->game, Carbon::now()->addHours(24)),
            'AttendanceReported' => new $fqcn(
                $fx->game,
                AttendanceReport::factory()->create([
                    'game_id' => $fx->game->id,
                    'reporter_id' => $fx->actor->id,
                    'reported_id' => $fx->recipient->id,
                ]),
            ),
            'AttendanceResolved' => new $fqcn($fx->game, AttendanceStatus::NoShow),
            'BadgeAwardedNotification' => new $fqcn('supporting_member', null, BadgeSource::Automatic, false),
            'BatchedNotification' => new $fqcn(
                NotificationCategory::ParticipantJoined,
                'game',
                $fx->game->id,
                $fx->game->name,
                route('games.show', ['locale' => app()->getLocale(), 'id' => $fx->game]),
                [
                    corpusBatchItem($fx->actor->id, $fx->actor->name, $fx->game),
                    corpusBatchItem($fx->gameOwner->id, $fx->gameOwner->name, $fx->game),
                ],
            ),
            'BelowMinPlayersWarning' => new $fqcn($fx->game, 1, 2),
            'BulletinPosted' => new $fqcn(
                $fx->game,
                $fx->gameOwner,
                $fx->bulletin ??= GameBulletin::factory()->create([
                    'game_id' => $fx->game->id,
                    'user_id' => $fx->gameOwner->id,
                ]),
            ),
            'CharterAmended' => (function () use ($fqcn, $fx) {
                $fx->charter ??= SessionZeroSurvey::factory()->charter()->create(['campaign_id' => $fx->campaign->id]);
                $fx->charterVersion ??= SessionZeroSurveyVersion::factory()->forSurvey($fx->charter)->create();

                return new $fqcn($fx->campaign, $fx->charter, $fx->charterVersion, true);
            })(),
            'CharterPublished' => new $fqcn(
                $fx->campaign,
                $fx->charter ??= SessionZeroSurvey::factory()->charter()->create(['campaign_id' => $fx->campaign->id]),
            ),
            'ConfirmationExpired' => new $fqcn($fx->game),
            'ContentRemoved' => new $fqcn('game', 'Corpus Game', 'violates the content policy'),
            'ContentReportWarning' => new $fqcn('game', 'Corpus Game', 'harassment report'),
            'DebriefingAvailable' => new $fqcn($fx->game),
            'DisputeResolved' => new $fqcn($fx->game, 'resolved_favor'),
            'EntityCancelled' => new $fqcn($fx->game),
            'EntityCompleted' => new $fqcn($fx->game),
            'EntityInvitation' => new $fqcn($fx->game, $fx->actor),
            'EntityUpdated' => new $fqcn($fx->game, ['date_time']),
            'EventAnnouncementPublished' => new $fqcn(
                $fx->announcement ??= EventAnnouncement::create([
                    'event_id' => ($fx->event ??= Event::factory()->create(['organizer_id' => $fx->actor->id]))->id,
                    'author_id' => $fx->actor->id,
                    'title' => 'Corpus announcement',
                    'content' => 'A corpus-rendered announcement body.',
                    'is_published' => true,
                ]),
                $fx->event,
            ),
            'EventCancelled' => new $fqcn($fx->event ??= Event::factory()->create(['organizer_id' => $fx->actor->id])),
            'EventCoOrganizerAdded' => new $fqcn(
                $fx->event ??= Event::factory()->create(['organizer_id' => $fx->actor->id]),
                $fx->actor,
            ),
            'EventRegistrationConfirmed' => new $fqcn(
                $fx->registration ??= EventRegistration::factory()->confirmed()->create([
                    'event_id' => ($fx->event ??= Event::factory()->create(['organizer_id' => $fx->actor->id]))->id,
                    'user_id' => $fx->recipient->id,
                ]),
            ),
            'GameSystemRequestApproved' => new $fqcn(corpusTicket($this, $fx->recipient), $fx->gameSystem ??= GameSystem::factory()->create()),
            'GameSystemRequestDuplicate' => new $fqcn(corpusTicket($this, $fx->recipient), $fx->gameSystem ??= GameSystem::factory()->create()),
            'GameSystemRequestRejected' => new $fqcn(corpusTicket($this, $fx->recipient)),
            'NewApplication' => new $fqcn($fx->actor, $fx->game, 'game'),
            'NewFollower' => new $fqcn($fx->actor),
            'ParticipantJoined' => new $fqcn($fx->actor, $fx->game, 'game'),
            'ParticipantRemoved' => new $fqcn($fx->actor, $fx->game, 'game'),
            'PlayerBenched' => new $fqcn($fx->game, 'game'),
            'PromotedFromBench' => new $fqcn($fx->game),
            'RecapPosted' => new $fqcn($fx->game, $fx->actor),
            'ReviewReported' => new $fqcn(
                $fx->review ??= Review::factory()->create(['report_reason' => 'inappropriate content']),
                $fx->actor,
            ),
            'SeatDemoted' => new $fqcn($fx->game, 'attendance'),
            'SessionAddedToCampaign' => new $fqcn(
                $fx->session ??= $this->createGameWithOwner(['campaign_id' => $fx->campaign->id])['game'],
                $fx->campaign,
            ),
            'SessionReminder' => new $fqcn($fx->game, '1h'),
            'TeamInvitation' => new $fqcn($fx->team ??= $this->createTeamWithCaptain()['team'], $fx->actor),
            'TeamMemberRemoved' => new $fqcn($fx->team ??= $this->createTeamWithCaptain()['team'], $fx->actor),
            'WaitlistExpiredRejected' => new $fqcn($fx->game, 2),
            'WaitlistPlaced' => new $fqcn($fx->game),
            'WaitlistPromoted' => new $fqcn($fx->game, Carbon::now()->addDays(2)),
            'WeeklyDigest' => (function () use ($fqcn, $fx) {
                // A realistic digest payload: rows the dispatcher would group.
                $fx->recipient->notifications()->createMany([
                    ['id' => Str::uuid(), 'type' => EntityInvitation::class, 'data' => ['type' => 'game_invitation']],
                    ['id' => Str::uuid(), 'type' => WaitlistPromoted::class, 'data' => ['type' => 'waitlist_promoted']],
                ]);

                return $fqcn::fromNotifications($fx->recipient->unreadNotifications);
            })(),
            default => throw new InvalidArgumentException("Unknown corpus class [{$class}]."),
        };
    };

    $notification = $make();
    $recipient = $this->fx->recipient;

    foreach (['en', 'de'] as $locale) {
        app()->setLocale($locale);

        // Mail — gated: SessionReminder (and any future database/push-only
        // class) has no toMail(); an ungated call fatals the loop.
        if (method_exists($notification, 'toMail')) {
            $mail = $notification->toMail($recipient);

            test()->assertNotSame('', (string) $mail->subject, "[{$class}/{$locale}] mail subject is empty.");

            // Preheader completeness pin (M081/S01/T02): every corpus class
            // with a toMail() must attach localized inbox-preview copy via
            // withPreheader() — a new class without one fails here, no
            // exceptions list. Copy lives under notifications.preheader_* in
            // both locales and must stay ≤ 90 chars rendered (inbox preview
            // truncation budget).
            $preheader = trim((string) ($mail->viewData['preheader'] ?? ''));
            test()->assertNotSame('', $preheader, "[{$class}/{$locale}] mail preheader is empty — every toMail() must attach withPreheader().");
            $preheaderLength = mb_strlen($preheader);
            test()->assertLessThanOrEqual(90, $preheaderLength, "[{$class}/{$locale}] mail preheader is {$preheaderLength} chars — preview copy must stay ≤ 90.");

            $html = (string) $mail->render();
            test()->assertNotSame('', $html, "[{$class}/{$locale}] rendered mail HTML is empty.");

            // The published notifications wrapper renders the preheader in
            // the hidden preview slot at the very top of the body: it must
            // appear escaped in the rendered HTML and BEFORE the greeting
            // heading, or mail clients would show body text as the preview.
            $preheaderPos = strpos($html, e($preheader));
            test()->assertNotFalse($preheaderPos, "[{$class}/{$locale}] rendered mail does not contain the preheader text — wrapper preview-slot wiring broken.");
            // …and BEFORE the brand header row (M081 sixth-pass review): the
            // wrapper rides the preheader in the header slot precisely so the
            // brand name cannot precede the crafted preview copy. A body-slot
            // preheader let clients concatenate "Roundup" ahead of it (or
            // preview only the brand), silently defeating per-class previews.
            $brandHeaderPos = strpos($html, 'class="header"');
            if ($preheaderPos !== false && $brandHeaderPos !== false) {
                test()->assertLessThan($brandHeaderPos, $preheaderPos, "[{$class}/{$locale}] preheader must render before the brand header row (hidden inbox-preview slot).\n");
            }
            $greetingPos = strpos($html, '<h1');
            if ($preheaderPos !== false && $greetingPos !== false) {
                test()->assertLessThan($greetingPos, $preheaderPos, "[{$class}/{$locale}] preheader must render before the greeting heading (hidden inbox-preview slot).");
            }

            // Excluded classes are listed in corpusUnsubscribeExclusions()
            // with a one-line justification — the exclusion-hygiene test
            // keeps that list honest.
            if (! array_key_exists($class, corpusUnsubscribeExclusions())) {
                test()->assertStringContainsString(
                    'signature=',
                    $html,
                    "[{$class}/{$locale}] rendered mail lacks the signed unsubscribe line."
                );

                // Unsubscribe-category validity guard (M080 review): any
                // category literal in a signed unsubscribe link must resolve
                // to a NotificationCategory case — NotificationController
                // tryFrom()s it and 404s anything else, so an unknown literal
                // here means a broken unsubscribe link in real mail. This
                // exact defect shipped for player_benched,
                // debriefing_available, and recap_posted because the
                // lifecycle tests pinned the same literals they asserted.
                // (WeeklyDigest legitimately carries no category link — its
                // opt-out is the distinct digest route, still covered by the
                // signature= assertion above.)
                preg_match_all('#/notifications/unsubscribe/[^/]+/([a-z_]+)\?#', $html, $matches);
                foreach ($matches[1] as $unsubCategory) {
                    test()->assertNotNull(
                        NotificationCategory::tryFrom($unsubCategory),
                        "[{$class}/{$locale}] unsubscribe category [{$unsubCategory}] is not a NotificationCategory case — the signed link would 404."
                    );
                }
            }

            // Cross-channel read-marker pin (D288 item 1, M081/S08): every
            // corpus mail CTA (outside corpusReadMarkerExclusions()) must be
            // a SIGNED read-marker URL — the marker is what keeps the bell
            // badge truthful after a mail click. A bare route() URL here
            // means a channel action escaped the actionUrlWithMarker wrap.
            if (! array_key_exists($class, corpusReadMarkerExclusions())) {
                preg_match_all('#href="([^"]*\/notifications\/read-marker/[^"]*)"#', $html, $markerMatches);
                test()->assertNotEmpty(
                    $markerMatches[1],
                    "[{$class}/{$locale}] mail CTA is not a signed read-marker URL — wire it through actionUrlWithMarker() or classify the exception."
                );
                foreach ($markerMatches[1] as $markerHref) {
                    test()->assertStringContainsString(
                        'signature=',
                        html_entity_decode($markerHref),
                        "[{$class}/{$locale}] read-marker CTA carries no signature — an unsigned marker would never mark the sibling row read."
                    );
                }
            }
        }

        // Database — gated for genuinely database-less classes. (WeeklyDigest
        // DOES define toDatabase — a category-identity payload for the
        // engagement counters, not a bell row; its supportedChannels() stays
        // mail-only.)
        if (method_exists($notification, 'toDatabase')) {
            $data = $notification->toDatabase($recipient);
            test()->assertNotEmpty($data, "[{$class}/{$locale}] toDatabase payload is empty.");

            // Counter-vocabulary guard (M080 3rd review): every corpus
            // class's attribution type must be a NotificationCategory value
            // or a conscious corpusTypeOnlyCategories() entry. Without this,
            // a new class tagging with an unlisted literal silently splits
            // the D284 counters into a vocabulary no dashboard can join.
            $type = $data['type'] ?? null;
            test()->assertIsString($type, "[{$class}/{$locale}] toDatabase payload lacks a non-empty string 'type' — the roundup_category tag would fall back to the class basename.");
            test()->assertNotSame('', $type, "[{$class}/{$locale}] toDatabase 'type' is empty.");
            test()->assertTrue(
                NotificationCategory::tryFrom($type) !== null || array_key_exists($type, corpusTypeOnlyCategories()),
                "[{$class}/{$locale}] toDatabase type [{$type}] is neither a NotificationCategory case nor a conscious corpusTypeOnlyCategories() entry — classify it consciously or fix the literal."
            );

            // Digest-label completeness pin (M081/S06 review): every emitted
            // type feeds WeeklyDigest::shortType(), whose label lookup falls
            // back to the English class basename for DE readers when a key is
            // missing (shipped for content_removed/cover_image_removed). A
            // new type without both locale keys fails here. WeeklyDigest
            // itself is exempt: it is mail-only, so its 'digest' category
            // identity feeds the engagement counters but can never appear as
            // an unread row a digest would summarize.
            if ($class !== 'WeeklyDigest') {
                foreach (['en', 'de'] as $labelLocale) {
                    $expected = "notifications.label_digest_{$type}";
                    test()->assertNotSame(
                        $expected,
                        __($expected, [], $labelLocale),
                        "[{$class}] digest label [{$expected}] is missing in [{$labelLocale}] — the digest row renders the English basename fallback."
                    );
                }
            }
        }

        // Push — gated + null-tolerant: null is a deliberate opt-out (D130).
        if (method_exists($notification, 'toPush')) {
            $push = $notification->toPush($recipient);

            if ($push !== null) {
                test()->assertNotSame('', $push->title, "[{$class}/{$locale}] push title is empty.");
                test()->assertNotSame('', $push->body, "[{$class}/{$locale}] push body is empty.");
                test()->assertSame(BaseNotification::PUSH_ICON, $push->icon, "[{$class}/{$locale}] push icon lost the shared envelope wrap.");
                test()->assertNotSame('', $push->url, "[{$class}/{$locale}] push url is empty.");

                // Read-marker pin (D288 item 1): every push action URL must
                // be a signed read-marker landing — tapping the push must
                // clear the sibling in-app row. All push-capable corpus
                // classes have a database sibling (only WeeklyDigest is
                // mail-only, and it has no toPush()).
                test()->assertStringContainsString(
                    '/notifications/read-marker/',
                    $push->url,
                    "[{$class}/{$locale}] push url is not a read-marker landing — wire it through actionUrlWithMarker()."
                );
                test()->assertStringContainsString(
                    'signature=',
                    $push->url,
                    "[{$class}/{$locale}] push read-marker carries no signature."
                );

                // Action buttons too (M081 sixth-pass review): a button URL
                // that skips the marker leaves the sibling bell row unread
                // after the user acts on the button — the same D288 breach a
                // bare url would commit. WaitlistPromoted's Confirm-spot
                // button is the current sole carrier; the pin keeps every
                // future action honest.
                foreach (($push->actions ?? []) as $actionIndex => $action) {
                    $actionUrl = is_string($action['url'] ?? null) ? $action['url'] : '';
                    test()->assertStringContainsString(
                        '/notifications/read-marker/',
                        $actionUrl,
                        "[{$class}/{$locale}] push action[{$actionIndex}] url is not a read-marker landing — wire it through actionUrlWithMarker()."
                    );
                    test()->assertStringContainsString(
                        'signature=',
                        $actionUrl,
                        "[{$class}/{$locale}] push action[{$actionIndex}] read-marker carries no signature."
                    );
                }
            }
        }

        // Discord — gated + null-tolerant: null is a deliberate opt-out
        // (D130/D247), non-null must be an explicit embed payload.
        if (method_exists($notification, 'toDiscord')) {
            $payload = $notification->toDiscord($recipient);

            if ($payload !== null) {
                $embed = $payload->embeds[0] ?? null;
                test()->assertNotNull($embed, "[{$class}/{$locale}] discord payload has no embed — explicit toDiscord override lost?");

                if ($embed !== null) {
                    test()->assertNotSame('', (string) $embed['title'], "[{$class}/{$locale}] discord embed title is empty.");
                    test()->assertNotSame('', (string) $embed['url'], "[{$class}/{$locale}] discord embed url is empty.");
                    test()->assertNotSame('', (string) $embed['description'], "[{$class}/{$locale}] discord embed description is empty.");
                }
            }
        }
    }
})->with(corpusNotificationDataset());

// ── Registry self-guards ──────────────────────────────────────────────────

it('registers every concrete BaseNotification subclass from app/Notifications', function () {
    $expected = collect(glob(app_path('Notifications').'/*.php'))
        ->map(fn (string $path): string => 'App\\Notifications\\'.basename($path, '.php'))
        ->filter(function (string $class): bool {
            if (! class_exists($class)) {
                return false;
            }

            $reflection = new \ReflectionClass($class);

            // Traits (RoutesCharter, RoutesGameOrCampaign), the abstract
            // BaseNotification itself, and Channels\ classes (different
            // directory) are all excluded — only concrete notifications.
            return $reflection->isSubclassOf(BaseNotification::class) && ! $reflection->isAbstract();
        })
        ->map(fn (string $class): string => basename(str_replace('\\', '/', $class)))
        ->sort()
        ->values()
        ->all();

    $registered = collect(corpusNotificationDataset())->keys()->sort()->values()->all();

    expect($registered)->toBe($expected);
});

it('keeps the unsubscribe exclusion list honest', function () {
    foreach (corpusUnsubscribeExclusions() as $class => $reason) {
        test()->assertArrayHasKey(
            $class,
            corpusNotificationDataset(),
            "Exclusion [{$class}] names a class outside the corpus registry — remove the stale entry."
        );
        test()->assertNotSame(
            '',
            trim($reason),
            "Exclusion [{$class}] must carry a one-line justification."
        );
    }
});

it('keeps the read-marker exclusion list honest', function () {
    foreach (corpusReadMarkerExclusions() as $class => $reason) {
        test()->assertArrayHasKey(
            $class,
            corpusNotificationDataset(),
            "Read-marker exclusion [{$class}] names a class outside the corpus registry — remove the stale entry."
        );
        test()->assertNotSame(
            '',
            trim($reason),
            "Read-marker exclusion [{$class}] must carry a one-line justification."
        );
    }
});

it('keeps the type-only category list honest', function () {
    foreach (corpusTypeOnlyCategories() as $type => $reason) {
        test()->assertNull(
            NotificationCategory::tryFrom($type),
            "Type-only entry [{$type}] is now a NotificationCategory case — remove the stale entry so the vocabulary converges."
        );
        test()->assertNotSame(
            '',
            trim($reason),
            "Type-only entry [{$type}] must carry a one-line justification."
        );
    }
});

it('classifies the dynamic type variants the corpus fixtures cannot reach', function () {
    // The corpus fixtures build BadgeAwardedNotification with upgraded=false
    // and ContentRemoved without a scope, so their variant types would
    // otherwise never pass the in-loop guard — pin them explicitly.
    $upgraded = (new BadgeAwardedNotification('supporting_member', null, BadgeSource::Automatic, true))
        ->toDatabase($this->fx->recipient);
    $covered = (new ContentRemoved('game', 'Corpus Game', 'violates the content policy', 'cover_image'))
        ->toDatabase($this->fx->recipient);

    foreach ([$upgraded['type'], $covered['type']] as $type) {
        test()->assertArrayHasKey(
            $type,
            corpusTypeOnlyCategories(),
            "Dynamic type variant [{$type}] must be classified in corpusTypeOnlyCategories()."
        );

        // Same digest-label completeness pin as the corpus loop — variants
        // the fixtures cannot reach still render digest rows.
        foreach (['en', 'de'] as $labelLocale) {
            $expected = "notifications.label_digest_{$type}";
            test()->assertNotSame(
                $expected,
                __($expected, [], $labelLocale),
                "Digest label [{$expected}] is missing in [{$labelLocale}] for dynamic variant [{$type}]."
            );
        }
    }
});

// ── Lazy cluster fixtures ─────────────────────────────────────────

/**
 * One buffered event snapshot for the BatchedNotification corpus fixture
 * — the item payload contract the buffer path (T04) writes and the
 * batched rendering replays (see BatchedNotification's docblock).
 *
 * @param  object  $game  Fixture game the batch is keyed on (stdClass|Game — id + name only).
 */
function corpusBatchItem(string $actorId, string $actorName, object $game): array
{
    return [
        'actor_id' => $actorId,
        'actor_name' => $actorName,
        'entity_type' => 'game',
        'entity_id' => $game->id,
        'entity_name' => $game->name,
        'event' => 'participant_joined',
        'context' => [],
        'action_url' => route('games.show', ['locale' => 'en', 'id' => $game->id]),
        'occurred_at' => Carbon::now()->subMinute()->toIso8601String(),
    ];
}

/**
 * Escalated game-system-request ticket (the GameSystemRequest* cluster),
 * created on first use inside a corpus case. Mirrors the ticket shape from
 * GameSystemRequestNotificationTest.
 */
function corpusTicket(TestCase $test, User $requester): Ticket
{
    $test->seed(EscalatedSetupSeeder::class);

    $department = Department::where('name', 'Game Systems')->firstOrFail();

    return Ticket::create([
        'requester_type' => (new User)->getMorphClass(),
        'requester_id' => $requester->id,
        'subject' => 'Game System Request: Catan',
        'description' => 'Please add Catan.',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Medium->value,
        'department_id' => $department->id,
        'ticket_type' => 'game_system_request',
        'channel' => TicketChannel::Web->value,
        'metadata' => [
            'game_system_request' => true,
            'bgg_url' => null,
            'publisher' => null,
            'designer' => null,
            'game_system_type' => 'boardgame',
            'game_system_id' => null,
        ],
    ]);
}

// ── Family-map coherence guard (M081 fifth-pass review) ───────────────────
//
// The enum's TYPE_ONLY_PREFERENCE_FAMILIES and the corpus annotations here
// must describe the SAME reality: every type-only vocabulary entry that is
// dispatched under a category (i.e. family-mapped in the enum) must be
// documented in corpusTypeOnlyCategories() — and, since the moderation
// cluster was family-mapped, the two sets are today identical. A new entry
// on either side without the other is exactly the silent-drift defect class
// this pass fixed (the moderation cluster was annotated "no preference
// category" while dispatch really gated it through ModerationNotice).
it('keeps the enum family map and the corpus type-only annotations in lockstep', function () {
    $families = (new \ReflectionClass(NotificationCategory::class))
        ->getConstant('TYPE_ONLY_PREFERENCE_FAMILIES');

    // 'digest' is the one documented family-less exemption: it is governed
    // by the separate weekly_digest_enabled flag, not a category preference
    // row (see the enum map's docblock).
    $annotated = array_filter(
        array_keys(corpusTypeOnlyCategories()),
        fn (string $type): bool => $type !== 'digest',
    );

    expect(array_keys($families))->toEqualCanonicalizing(array_values($annotated));
});
