<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\PushChannel;
use App\Services\Calendar\IcsAttachment;
use App\Services\Calendar\IcsBuilder;
use App\Services\Discord\DiscordPalette;
use App\Services\Discord\DiscordWebhookPayload;
use App\Services\Mail\SignedUnsubscribeUrls;
use App\Services\Notifications\SignedReadMarker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Base notification for all application notifications.
 *
 * All notifications implement ShouldQueue so they are dispatched to the
 * queue via NotificationService. In the test environment (QUEUE_CONNECTION=sync)
 * they run synchronously, keeping test assertions straightforward.
 *
 * Channel resolution has two layers:
 *   1. supportedChannels() — what this notification TYPE can use (e.g. a
 *      database-only notification returns just DatabaseChannel).
 *   2. resolvedChannels — the recipient-specific subset (supported ∩ enabled
 *      in their notification_settings), set by NotificationService::send()
 *      before dispatch.
 *
 * via() returns resolvedChannels when set, falling back to supportedChannels().
 * Laravel's queued dispatcher reads via() at enqueue time to decide which
 * channels get jobs, so returning the resolved subset propagates the user's
 * preferences (e.g. mail disabled) across the queue boundary.
 *
 * Envelope helpers (M072/S04): the shared notification envelope — locale
 * resolution, greeting, default actor, the push payload wrap (icon/tag), the
 * Discord embed wrap (blurple color), and the signed unsubscribe line — is
 * concentrated in this class so a wording or branding tweak lands here
 * instead of 40+ subclass files. The base deliberately does NOT define
 * toDiscord()/toPush(): every subclass keeps an explicit per-channel method
 * (payload = DM/push; null = deliberate opt-out, D130/D247) — a channel's
 * voice must always be written, not derived.
 *
 * @method array<string, mixed> toDatabase(User $notifiable) Every concrete corpus class carries a database payload; actionUrlWithMarker() derives the read-marker's entity binding from it.
 */
abstract class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Discord embed default color — the roundup emerald accent (canonical home: DiscordPalette, D280). */
    public const DISCORD_EMBED_COLOR = DiscordPalette::EMERALD;

    /** PWA icon shared by every push payload. */
    public const PUSH_ICON = '/icons/pwa-192x192.png';

    /**
     * Monochrome status-bar badge (Android tray, D286): the five-pip die
     * derived from the Material Symbols `casino` glyph — white-on-transparent
     * 96×96; Android masks the alpha channel and tints it itself.
     */
    public const string PUSH_BADGE = '/icons/push-badge.png';

    /**
     * Channels resolved by NotificationService for a specific recipient —
     * the intersection of what this notification supports and what the
     * recipient has enabled. Null until the service sets it.
     *
     * @var array<int, string>|null
     */
    protected ?array $resolvedChannels = null;

    /**
     * Channels this notification type supports by default. Subclasses
     * override to declare a narrower set (e.g. database-only notifications).
     *
     * Push is auto-detected: a notification that implements toPush() declares
     * push intent, so PushChannel is included automatically. This keeps the
     * 30+ toPush() implementations reachable without each subclass repeating
     * the channel declaration.
     *
     * Discord (D118) is unconditionally supported, but its payload is
     * NEVER synthesized: every notification defines an explicit
     * toDiscord() (payload = DM; null = deliberate opt-out, D130). A
     * class missing toDiscord() is skipped with a warning at send time
     * (D247) — the platform's DM voice must always be written, not
     * derived. DiscordChannel is a graceful no-op when unlinked.
     *
     * @return array<int, string>
     */
    protected function supportedChannels(): array
    {
        $channels = [DatabaseChannel::class, MailChannel::class, DiscordChannel::class];

        if (method_exists($this, 'toPush')) {
            $channels[] = PushChannel::class;
        }

        return $channels;
    }

    /**
     * Channels Laravel will dispatch to. Returns the recipient-specific
     * resolved set when set by NotificationService, otherwise the type's
     * supported channels (fallback for direct notify() calls / tests).
     *
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return $this->resolvedChannels ?? $this->supportedChannels();
    }

    /**
     * Restrict dispatch to the given channels. Called by
     * NotificationService::send() after intersecting the recipient's enabled
     * channels with this notification's supported channels.
     *
     * @param  array<int, string>  $channels
     */
    public function setResolvedChannels(array $channels): static
    {
        $this->resolvedChannels = $channels;

        return $this;
    }

    // ── Envelope helpers ───────────────────────────────────────────────
    // The shared envelope every channel copy/payload is wrapped in. Use
    // these instead of re-stating the literal locale/greeting/icon/color
    // lines per subclass.

    /**
     * Resolve the recipient's locale for URLs and copy: their explicit
     * preferred_language when set, otherwise the application default.
     */
    protected function resolveLocale(User $notifiable): string
    {
        return $notifiable->preferred_language->value ?? app()->getLocale();
    }

    /**
     * Localized mail greeting addressing the recipient by name, falling
     * back to their email address when no name is set.
     */
    protected function greetingLine(User $notifiable): string
    {
        return __('common.field_hey_name', ['name' => $notifiable->name ?? $notifiable->email]);
    }

    /**
     * Default actor for block-list checking by NotificationService.
     *
     * The default is null (system notification, nothing to check against a
     * block list). Subclasses with a triggering user override this to return
     * them, so blocked-actor filtering applies.
     */
    public function getActor(): ?User
    {
        return null;
    }

    /**
     * Burst batch key for the defer window (D296, M082/S01): identifies
     * WHICH open batch this event joins — the buffer path in
     * NotificationService::send() looks the batch up by
     * (recipient, batch_key), where batch_key is 'category:entityUuid'
     * (e.g. 'participant_joined:<game uuid>').
     *
     * The base returns null — an un-overridden notification NEVER batches
     * and send() dispatches it immediately. The two halves of the policy
     * must agree: send() buffers only when batchKey() returns non-null AND
     * NotificationCategory::isBatchable() is true for the category (a
     * batchable category whose notification forgets the override stays
     * immediate — safe default in both directions).
     *
     * BatchedNotification (T03) inherits this null default deliberately:
     * flushed batches flow back through send() and must never re-buffer
     * (the re-entry guard's second arm; the instanceof check is the first).
     */
    public function batchKey(User $notifiable): ?string
    {
        return null;
    }

    /**
     * Per-event extras for the burst-batch item snapshot (D296, M082):
     * whatever this bufferable event's category needs BEYOND the shared
     * actor/entity snapshot — e.g. SessionAddedToCampaign's session
     * identity, which the batched mail needs for its per-session ICS
     * invitations and session-named digest lines.
     *
     * The base returns an empty array; only bufferable classes whose
     * batched rendering needs per-event detail override it. Values must
     * be scalar or nested arrays of scalars (jsonb-safe) and are stored
     * verbatim under the item payload's 'context' key.
     *
     * @return array<string, mixed>
     */
    public function batchItemContext(User $notifiable): array
    {
        return [];
    }

    /**
     * Push payload wrapped in the shared envelope: platform PWA icon plus
     * the caller's title/body/url/tag and the optional richness fields
     * (M081/S05): action buttons, the event timestamp (epoch seconds — the
     * Web Push display timestamp), and renotify, which the PushPayload
     * constructor guards to require a non-empty tag.
     *
     * @param  array<int, array{label: string, url: string, action?: string}>|null  $actions
     */
    protected function pushPayload(
        string $title,
        string $body,
        string $url,
        ?string $tag = null,
        ?array $actions = null,
        ?int $timestamp = null,
        ?string $badge = null,
        bool $renotify = false,
    ): PushPayload {
        return new PushPayload(
            title: $title,
            body: $body,
            icon: self::PUSH_ICON,
            url: $url,
            tag: $tag,
            actions: $actions,
            timestamp: $timestamp,
            badge: $badge,
            renotify: $renotify,
        );
    }

    /**
     * Discord DM wrapped in the shared envelope: one embed with the
     * caller's title/url/description (D130: Discord mirrors push). The
     * color resolves through the semantic layer (D280): an explicit $color
     * wins, else semanticColor() per class, else the emerald default.
     *
     * Enrichment (M081/S04/T02): an optional thumbnail URL and author block
     * render only when the caller provides them — null keys are omitted so
     * Discord applies its own defaults rather than receiving explicit nulls
     * (which Discord treats as "clear this field").
     *
     * @param  array{name: string, url?: string}|null  $author
     */
    protected function discordEmbed(
        string $title,
        string $url,
        string $description,
        ?int $color = null,
        ?string $thumbnailUrl = null,
        ?array $author = null,
    ): DiscordWebhookPayload {
        $embed = [
            'title' => $title,
            'url' => $url,
            'description' => $description,
            'color' => $color ?? $this->semanticColor(),
        ];

        if ($thumbnailUrl !== null && $thumbnailUrl !== '') {
            $embed['thumbnail'] = ['url' => $thumbnailUrl];
        }

        if ($author !== null) {
            $embed['author'] = $author;
        }

        return DiscordWebhookPayload::embed($embed);
    }

    /**
     * Semantic status color for this notification's Discord DM embed (D280).
     *
     * The base returns the roundup brand accent (emerald) — the single
     * default every embed carries. Subclasses whose meaning maps onto a
     * semantic bucket override this and return the palette constant:
     * cancellations red (EntityCancelled, EventCancelled,
     * ConfirmationExpired, WaitlistExpiredRejected),
     * completed/ambient grey (EntityCompleted, BulletinPosted, RecapPosted),
     * action-needed amber (EntityInvitation, BelowMinPlayersWarning,
     * AttendanceNudge, the GameSystemRequest family). The layering is
     * deliberate — brand accent first, semantics on top — so the DM design
     * language stays conscious per class (D280) instead of falling out of
     * incidental hex picks. Classes whose toDiscord() is a null opt-out
     * still declare their bucket, so a future DM inherits the right color.
     */
    protected function semanticColor(): int
    {
        return DiscordPalette::EMERALD;
    }

    /**
     * Attach the localized inbox preheader to a mail message (D279).
     *
     * The preheader is the hidden preview line mail clients show next to
     * the subject. It travels as MailMessage view data ('preheader') —
     * public and merged into data() by the framework — so the published
     * notifications wrapper renders it in the hidden preview slot at the
     * very top of the body. No per-class view override is needed, and
     * queued/TaggedMailChannel sends are unaffected. Copy lives under
     * notifications.preheader_* in every locale; completeness is pinned
     * by NotificationCorpusRenderTest, which fails for any toMail()
     * class that does not attach one.
     *
     * $choice (M082 review) selects the pluralization arm for
     * count-bearing preheader keys — pass the number the copy counts;
     * non-choice keys ignore it.
     *
     * @param  array<string, string>  $replace
     */
    protected function withPreheader(MailMessage $message, string $key, array $replace = [], ?int $choice = null): MailMessage
    {
        $message->viewData['preheader'] = $choice !== null
            ? trans_choice($key, $choice, $replace)
            : __($key, $replace);

        return $message;
    }

    /**
     * Generate a signed unsubscribe URL for the given user and category.
     *
     * Delegates to SignedUnsubscribeUrls (M081/S02/T01) — the single
     * generator shared with the digest action and the TaggedMailChannel
     * List-Unsubscribe headers, so body links and mail headers can never
     * drift onto different URL constructions.
     */
    protected function unsubscribeUrl(User $notifiable, string $category): string
    {
        return app(SignedUnsubscribeUrls::class)->forCategory(
            $notifiable,
            $this->resolveLocale($notifiable),
            $category,
        );
    }

    /**
     * Generate a styled unsubscribe line suitable for markdown emails.
     */
    protected function unsubscribeLine(User $notifiable, string $category): HtmlString
    {
        $url = $this->unsubscribeUrl($notifiable, $category);
        $label = __('notifications.email_unsubscribe');

        // The raw URL travels as visible small text after the link: the text
        // part strips tags (strip_tags in the text layout), so without it a
        // text-part reader gets the label but no way to opt out (M081
        // fifth-pass review). Both parts read "label (https://…)".
        return new HtmlString('<small><a href="'.$url.'" style="color: #5c5641;">'.$label.'</a> ('.$url.')</small>');
    }

    /**
     * Wrap a channel action target in the signed cross-channel read
     * marker (D288 item 1, M081/S08): landing on the marker URL marks the
     * sibling in-app notification read via markReadByType, then redirects
     * to the original target — so the bell badge stays truthful after the
     * user acts on mail or push, not just in-app.
     *
     * Use for toMail ->action() URLs and toPush() urls of classes that
     * have a database sibling (a bell row). Mail-only classes (WeeklyDigest)
     * skip the marker — there is no in-app row to clear.
     *
     * Binding derivation: $type defaults to this notification's class name
     * (what the notifications.type column stores); the entity pair is
     * derived from the toDatabase payload — the first id key of
     * SignedReadMarker::ALLOWED_DATA_KEYS the payload carries, so the
     * marker queries exactly the key the bell row was stored under
     * (entity_id for most classes; game_id/campaign_id for the
     * RoutesGameOrCampaign family; team_id for TeamInvitation). Payloads
     * with no whitelisted id (one-off notices: AccountSuspended,
     * moderation mail) bind user+type only — clicking then marks that
     * type's unread rows read.
     */
    protected function actionUrlWithMarker(
        User $notifiable,
        string $url,
        ?string $type = null,
        int|string|null $entityId = null,
        ?string $dataKey = null,
        ?string $category = null,
    ): string {
        [$derivedEntityId, $derivedDataKey] = $this->deriveEntityBinding($notifiable);

        return app(SignedReadMarker::class)->build(
            $notifiable,
            $this->resolveLocale($notifiable),
            $type ?? static::class,
            $entityId ?? $derivedEntityId,
            $dataKey ?? $derivedDataKey,
            $this->relativeActionPath($url),
            $category,
        );
    }

    /**
     * Entity binding (id + payload key) this notification's bell rows are
     * stored under, derived from the toDatabase payload.
     *
     * @return array{0: int|string|null, 1: string|null} [entityId, dataKey]
     */
    private function deriveEntityBinding(User $notifiable): array
    {
        $payload = $this->toDatabase($notifiable);

        foreach (SignedReadMarker::ALLOWED_DATA_KEYS as $key) {
            $value = $payload[$key] ?? null;

            if (is_int($value) || is_string($value)) {
                return [$value, $key];
            }
        }

        return [null, null];
    }

    /**
     * Collapse an absolute action URL to its root-relative path (+ query +
     * fragment) — the marker's redirect target. The redirect must be an
     * internal path (open-redirect guard on landing), and the path already
     * carries its own locale segment.
     */
    private function relativeActionPath(string $url): string
    {
        $components = parse_url($url);

        if (! is_array($components)) {
            return '/';
        }

        return ($components['path'] ?? '/')
            .(isset($components['query']) ? '?'.$components['query'] : '')
            .(isset($components['fragment']) ? '#'.$components['fragment'] : '');
    }

    // ── iTIP calendar attachments (D287, M081/S07) ─────────────────────
    //
    // The shared RFC 5545/5546 lifecycle payload: METHOD:REQUEST invites
    // and same-UID updates, METHOD:CANCEL revocations — rendered by
    // IcsBuilder and attached as a text/calendar part whose Content-Type
    // method= parameter drives the client's Add-to-calendar handling.
    // Entities that resolve to no VEVENT (a bare campaign — a container,
    // not a scheduled occurrence — or an entity without a date) attach
    // nothing, and the mail renders unchanged.

    /**
     * Attach the iTIP METHOD:REQUEST calendar payload for the entity: the
     * initial calendar invitation, or a same-UID update (SEQUENCE bump)
     * when the recipient already holds the event.
     */
    protected function attachIcsRequest(MailMessage $mail, Game|Campaign|Event $entity, User $recipient, string $url): void
    {
        $this->attachIcs($mail, app(IcsBuilder::class)->request($entity, $recipient, $url));
    }

    /**
     * Attach the iTIP METHOD:CANCEL twin — STATUS:CANCELLED plus a
     * SEQUENCE increment so clients drop the event they hold for this UID
     * (cancellations, seat revocations).
     */
    protected function attachIcsCancellation(MailMessage $mail, Game|Campaign|Event $entity, User $recipient, string $url): void
    {
        $this->attachIcs($mail, app(IcsBuilder::class)->cancel($entity, $recipient, $url));
    }

    private function attachIcs(MailMessage $mail, ?IcsAttachment $ics): void
    {
        if ($ics !== null) {
            $mail->attachData($ics->content, $ics->filename, ['mime' => $ics->mimeType]);
        }
    }
}
