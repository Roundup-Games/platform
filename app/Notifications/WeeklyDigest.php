<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use App\Services\Mail\SignedUnsubscribeUrls;
use App\Services\NotificationEngagementCounters;
use App\Services\NotificationQueryService;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Weekly digest email summarising a user's unread in-app notifications.
 *
 * This is the cost-conscious alternative to per-category email: users who keep
 * email OFF for noisy/informational categories (the default) still receive ONE
 * weekly summary, keeping them in contact at ~1/50th the email volume.
 *
 * Deliberately bypasses the NotificationService category system — the digest is
 * not a per-category event, has no actor, and respects only the user's
 * weekly_digest_enabled flag (checked by the sender command before dispatch).
 * Sent via direct $user->notify() from SendWeeklyDigest, queued via ShouldQueue
 * (inherited from BaseNotification, which also supplies the shared envelope:
 * resolveLocale/greetingLine). Its opt-out URL is a distinct digest route with
 * no category, so it does NOT reuse the base's unsubscribeUrl/unsubscribeLine.
 */
class WeeklyDigest extends BaseNotification
{
    /**
     * Build from live notification rows, projecting each to the minimal
     * shape the mail needs ({type, data}). The projection IS the point:
     * ShouldQueue serializes this notification — constructor-held Eloquent
     * models ride the queue payload whole (ids, notifiable keys, read_at,
     * timestamps on every row), so a user with hundreds of unread rows
     * produced a multi-hundred-KB Monday-morning queue payload for a mail
     * that renders ten rows (M081 fifth-pass review).
     *
     * @param  Collection<int, DatabaseNotification>  $notifications
     */
    public static function fromNotifications(Collection $notifications): self
    {
        return new self(
            $notifications->map(function (DatabaseNotification $notification): array {
                /** @var array<string, mixed> $data */
                $data = is_array($notification->data ?? null) ? $notification->data : [];

                return ['type' => $notification->type, 'data' => $data];
            })
        );
    }

    /**
     * @param  Collection<int, array{type: string, data: array<string, mixed>}>  $rows  Projected unread rows (see fromNotifications).
     */
    public function __construct(
        public Collection $rows,
    ) {}

    /**
     * Max per-entity rows rendered before the overflow tier link (M081/S06/T01).
     */
    private const MAX_DIGEST_ROWS = 10;

    /**
     * Mail-only — the digest exists because these notifications were already
     * delivered in-app; the email is the supplementary summary channel.
     * Overrides the base's default support set (database + mail + discord):
     * the digest deliberately has no in-app bell row and no DM surface.
     *
     * @return array<int, string>
     */
    protected function supportedChannels(): array
    {
        return [MailChannel::class];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);

        $message = (new MailMessage)
            ->subject(__('notifications.subject_weekly_digest'))
            ->greeting($this->greetingLine($notifiable))
            ->line(trans_choice('notifications.body_weekly_digest_intro', $this->rows->count(), ['count' => $this->rows->count()]));

        // Per-notification entity-aware rows (M081/S06/T01): keep the digest's
        // scannability ordering — group by type label, biggest group first —
        // but render one row PER notification so each entry can deep-link the
        // recipient straight to the entity it is about. Names resolve through
        // the same extraction the in-app bell rows use; rows whose stored
        // payload lacks a resolvable name or action_url degrade to the plain
        // type-label line (see digestRow()) — never a fabricated link.
        /** @var Collection<int, array{type: string, data: array<string, mixed>}> $rows */
        $rows = $this->rows
            ->groupBy(fn (array $row) => $this->shortType($row, $locale))
            ->sortByDesc(fn (Collection $items) => $items->count())
            // flatMap, NOT flatten(): rows are plain arrays now, and
            // flatten() would descend into them and dissolve the row shape
            // (with the old Eloquent rows it stopped at the objects).
            ->flatMap(fn (Collection $items) => $items->values());

        $shown = $rows->take(self::MAX_DIGEST_ROWS);
        foreach ($shown as $row) {
            $message->line($this->digestRow($row, $locale));
        }

        $remaining = $rows->count() - $shown->count();
        if ($remaining > 0) {
            $message->line($this->moreLine($remaining, $locale));
        }

        // ONE ->action() slot exists on SimpleMessage (a second call
        // overwrites the first): the primary CTA is the notifications
        // index. The digest opt-out rides as a small HtmlString line link —
        // the same mechanism as BaseNotification::unsubscribeLine() — and
        // the published mail wrapper's partitioner relocates any outro line
        // containing '/notifications/unsubscribe' (the digest URL
        // '/notifications/unsubscribe-digest/...' matches the prefix) into
        // the bordered footer slot, so it can never compete with the CTA
        // button (M081 fifth-pass review: the former double ->action() left
        // 'Unsubscribe' as the digest's ONLY hero button).
        $message->action(__('notifications.action_weekly_digest'), route('notifications.index', ['locale' => $locale]))
            ->line(__('notifications.body_weekly_digest_unsubscribe'))
            ->line(new HtmlString('<small><a href="'.e($this->digestUnsubscribeUrl($notifiable)).'" style="color: #5c5641;">'.e(__('notifications.action_unsubscribe_digest')).'</a></small>'))
            ->line(__('notifications.body_weekly_digest_settings'));

        return $this->withPreheader($message, 'notifications.preheader_weekly_digest');
    }

    /**
     * One entity-aware digest row: the entity's NAME deep-linked to the
     * action_url the in-app payload already carries (the same field the
     * bell rows link through), with the name resolved through the SAME
     * extraction the in-app rows use (NotificationQueryService::
     * extractEntityName) — payload name keys are heterogeneous
     * (game_name/campaign_name for the unified Entity* classes, team_name,
     * session_name, game_system_name, …), so reading data['entity_name']
     * directly silently dropped invitations, team invitations and
     * game-system requests to label-only rows. Rows whose stored payload
     * lacks a resolvable name or action_url degrade to the plain
     * type-label line — never a fabricated link.
     *
     * The link must travel as an HtmlString: Laravel's secured markdown mail
     * encoding escapes [ and ] inside echoed line strings precisely so line
     * content cannot inject markdown links, but Htmlable lines echo raw HTML
     * (the same mechanism the base's unsubscribeLine() uses). Plain anchors
     * need no inline styling — the roundup mail theme styles every anchor.
     */
    /**
     * @param  array{type: string, data: array<string, mixed>}  $row
     */
    private function digestRow(array $row, string $locale): HtmlString|string
    {
        $data = $row['data'];
        $url = $data['action_url'] ?? null;
        $name = app(NotificationQueryService::class)
            ->extractEntityName(class_basename($row['type']), $data);

        if (is_string($name) && $name !== '' && is_string($url) && $url !== '') {
            return new HtmlString('• <a href="'.e($url).'">'.e($name).'</a>');
        }

        // The row degrades to label-only: no deep link, so the degradation
        // itself is the coverage signal (D294 rider 2 — see countDegradedRow).
        $this->countDegradedRow();

        return '• '.$this->shortType($row, $locale);
    }

    /**
     * Best-effort degraded-row counter (D294 rider 2, M082/S03): a row that
     * renders label-only is a deep-link COVERAGE GAP — its stored payload
     * lacked a resolvable entity name or action_url, so Monday's digest was
     * the last chance to deep-link it and could not. Counting under
     * ('digest', 'mail', 'row_degraded') makes the weekly digest volume the
     * coverage signal: the digest funnel's delivered-vs-degraded ratio shows
     * how much digest volume is unlinkable without per-send grepping.
     *
     * 'digest' is the canonical counter category the mail funnel already
     * joins on (Resend-webhook mail attribution and unsubscribeDigest() both
     * count under it) — a second 'weekly_digest' category would split the
     * funnel, violating D284's one-row-set invariant. Called from
     * digestRow's fallback branch only, so one render counts each degraded
     * row exactly once (digest rendering happens once per send).
     *
     * Container-resolved, not constructor-injected: ShouldQueue serializes
     * this notification, so it must hold no service dependencies — the same
     * reason digestRow resolves NotificationQueryService through the
     * container. Counter failure is logged and swallowed (countUnsubscribe
     * pattern): the mail render is load-bearing and must never degrade on
     * telemetry.
     */
    private function countDegradedRow(): void
    {
        try {
            app(NotificationEngagementCounters::class)->incrementFor('digest', 'mail', 'row_degraded');
        } catch (Throwable $e) {
            Log::warning('notifications.engagement_counter_failed', [
                'category' => 'digest',
                'channel' => 'mail',
                'event' => 'row_degraded',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Overflow tier line for the rows beyond MAX_DIGEST_ROWS: the existing
     * body_weekly_digest_more copy, now a link to the notifications page so
     * the overflow is one click from the full history. The translation key is
     * reused verbatim — EN and DE both already define it; no new tier key.
     */
    private function moreLine(int $remaining, string $locale): HtmlString
    {
        return new HtmlString(
            '<a href="'.e(route('notifications.index', ['locale' => $locale])).'">'
            .e(__('notifications.body_weekly_digest_more', ['count' => $remaining], $locale))
            .'</a>'
        );
    }

    /**
     * Category identity for engagement attribution (M080/S03): 'digest' is
     * the canonical counter vocabulary — the Resend-webhook counters attribute
     * mail via this type, and NotificationController::unsubscribeDigest()
     * counts its opt-outs under the same key, so the digest's delivered-vs-
     * unsubscribed funnel joins on one row set (D284).
     *
     * NOT a bell-row declaration: supportedChannels() stays mail-only, so the
     * framework's DatabaseChannel never runs and no in-app notification is
     * created — the payload exists so ResolvesNotificationCategory (and the
     * corpus render harness) see 'digest' instead of the 'WeeklyDigest'
     * class-basename fallback.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'type' => 'digest',
            'count' => $this->rows->count(),
        ];
    }

    /**
     * Discord opted out (D130): mail-only by design — the digest summarizes
     * in-app notifications, so a DM would duplicate the channel it exists
     * to supplement.
     */
    public function toDiscord(User $notifiable): ?DiscordWebhookPayload
    {
        return null;
    }

    /**
     * Signed one-click opt-out URL for the weekly digest (a distinct route
     * from the base's per-category unsubscribe — the digest has no
     * category). Delegates to SignedUnsubscribeUrls (M081/S02/T01) so the
     * body action and the List-Unsubscribe header share one generator.
     */
    private function digestUnsubscribeUrl(User $notifiable): string
    {
        return app(SignedUnsubscribeUrls::class)->forDigest(
            $notifiable,
            $this->resolveLocale($notifiable),
        );
    }

    /**
     * Resolve a localized digest label for a notification row.
     *
     * Prefers the per-notification data.type discriminator (e.g. 'game_invitation'
     * vs 'campaign_invitation' from EntityInvitation) — the value the
     * label_digest_* translation keys are actually keyed by — falling back to
     * the snake_cased class basename when data.type is absent, and finally to a
     * Title-Case basename so an unmapped/legacy type never breaks the digest.
     */
    /**
     * @param  array{type: string, data: array<string, mixed>}  $row
     */
    private function shortType(array $row, string $locale): string
    {
        $classBase = class_basename($row['type']);

        $data = $row['data'];
        $key = is_string($data['type'] ?? null) && $data['type'] !== ''
            ? $data['type']
            : strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $classBase));

        // Flat lang-file convention: label_digest_{type} (i18n:check enforces
        // flat prefixed keys — no nested digest_labels array). The
        // concatenation lives INSIDE __() so i18n:dead-strings' dynamic-prefix
        // detector registers 'label_digest_' as a live prefix.
        $expected = 'notifications.label_digest_'.$key;
        $label = __('notifications.label_digest_'.$key, [], $locale);

        // __('...') returns the key itself when absent; fall back to a
        // Title-Case class basename so the digest still renders.
        if ($label !== $expected) {
            return $label;
        }

        return preg_replace('/(?<!^)([A-Z])/', ' $1', $classBase) ?? $classBase;
    }
}
