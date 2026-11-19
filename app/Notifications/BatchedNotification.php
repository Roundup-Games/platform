<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Enums\NotificationCategory;
use App\Models\Game;
use App\Models\NotificationBatch;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Carbon\Carbon;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * One notification carrying a flushed NotificationBatch (D296, M082/S01) —
 * the per-channel batch rendering (S02/T02): one batch renders ONE
 * canon-clean notification per channel, GitHub-digest style.
 *
 * Constructed by FlushNotificationBatch at flush time from the buffered
 * item snapshots — the batch's underlying category plus the entity's
 * identity and action URL (baked at buffer time, already resolved for the
 * recipient's locale; the campaign-hosted session arm re-targets the
 * campaign's own page at render time — see entityActionUrl()).
 * Individual events are never dispatched once buffered.
 *
 * Item payload contract (written by the buffer path in
 * NotificationService::send(), T04; consumed here and by
 * NotificationBatch::digestVariables()):
 *
 *  - actor_id    string  the triggering user's uuid
 *  - actor_name  string  their display name
 *  - entity_type string  'game' | 'campaign'
 *  - entity_id   string  the batch-key entity's uuid
 *  - entity_name string  the entity's name
 *  - event       string  the underlying category value
 *  - context     array   event-specific extras (empty for the v1 set)
 *  - action_url  string  absolute action URL for the event
 *  - occurred_at string  ISO-8601 buffer instant
 *
 * Category attribution (DECIDED, S02/T02): the UNDERLYING category rides
 * toDatabase()['type'] (participant_joined etc.), so the bell's type+day
 * grouping, the channel engagement counters (ResolvesNotificationCategory
 * reads data.type), and the S03 matrix treat batched rows as first-class
 * siblings of their unbatched counterparts. Re-entry safety comes from the
 * send() instance guard plus batchKey() staying inherited (null) — NOT
 * from the type.
 *
 * batchKey() stays inherited (null): a flushed batch flows back through
 * NotificationService::send() and must NEVER re-buffer — the inherited
 * null default is the re-entry guard's second arm (the instanceof check
 * in send() is the first). getActor() also stays inherited (null):
 * block-list filtering already ran per actor at buffer time, so a batch
 * of mixed actors re-checking one actor would be wrong.
 */
class BatchedNotification extends BaseNotification
{
    /**
     * Mail digest tier boundary (S02/T02): batches of at most this many
     * activities render one full line per buffered event; larger batches
     * collapse to the headline ("actors + count") tier.
     */
    public const int MAIL_FULL_LINE_ITEMS = 3;

    /**
     * Push actor display width: the compact prefix names this many actors
     * ("Sarah, Alex +3") before collapsing to a count.
     */
    public const int PUSH_ACTOR_NAMES = 2;

    /**
     * Interrupt-channel floor (M082 review): push and Discord fire only
     * for bursts of at least this many buffered events. A single buffered
     * event is the pre-M082 case wearing a batch key — it keeps the quiet
     * surface its immediate dispatch always had (in-app row + mail), so
     * the defer window never MANUFACTURES interrupts (D286: users tolerate
     * information volume, not interrupt volume). The in-app row and mail
     * digest render for every batch size — they carry information, not
     * interrupts. Discord mirrors push's floor (D130).
     */
    public const int MIN_INTERRUPT_ITEMS = 2;

    /**
     * @param  NotificationCategory  $category  The UNDERLYING category — governs preference resolution and rides the payload as data.type.
     * @param  string  $entityType  'game' | 'campaign'.
     * @param  string  $entityId  The batch-key entity's uuid (read-marker binding + UI links).
     * @param  string  $entityName  The entity's display name.
     * @param  string  $actionUrl  Absolute action URL from the first buffered item (recipient locale) — the batch-level target every channel links; see entityActionUrl() for the session-burst override.
     * @param  array<int, array<string, mixed>>  $items  Buffered event snapshots, in append order.
     */
    public function __construct(
        public readonly NotificationCategory $category,
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly string $entityName,
        public readonly string $actionUrl,
        public readonly array $items,
    ) {}

    /**
     * Grouped bell payload. 'type' carries the UNDERLYING category value
     * so the bell resolves category, settings shortcut, and verb through
     * its data.type tier and treats the batched row first-class next to
     * its unbatched siblings; the entity name rides the conventional
     * "{entity_type}_name" key the read side already falls back to, and
     * actor_names/actor_ids list EVERY distinct actor in arrival order as
     * position-aligned lists (the in-app row is the one surface with room
     * for all the names — push and mail collapse to the capped prefix; the
     * ids pair the names to profile links in the grouped display).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'type' => $this->category->value,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            "{$this->entityType}_name" => $this->entityName,
            'actor_names' => $this->actorNames(),
            'actor_ids' => $this->actorIds(),
            'action_url' => $this->entityActionUrl($notifiable),
            'batched' => true,
            'batch_count' => count($this->items),
        ];
    }

    /**
     * Tiered mail digest (S02/T02): up to MAIL_FULL_LINE_ITEMS activities
     * render one full line per buffered event; larger batches collapse to
     * the headline tier ("Sarah, Alex +3 joined your Runde — :count
     * updates in total"). Standard envelope throughout: greeting, the
     * entity deep link as a marker-wrapped CTA, the signed unsubscribe
     * line targeting the UNDERLYING category's preference row, and the
     * localized preheader.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $digest = NotificationBatch::digestVariables($this->items);
        $total = $digest['total_activities'];

        $mail = (new MailMessage)
            ->subject(trans_choice('notifications.subject_batched', $total, [
                'count' => $total,
                'entity' => $this->entityName,
            ]))
            ->greeting($this->greetingLine($notifiable));

        if ($total <= self::MAIL_FULL_LINE_ITEMS) {
            foreach ($digest['activities_preview'] as $item) {
                $actor = is_string($item['actor_name'] ?? null) ? $item['actor_name'] : '';

                // Session bursts name the SESSION in each line — one host
                // scheduling several sessions would otherwise render
                // identical actor-only lines (the session is the news).
                // Snapshots without session identity (legacy in-flight
                // buffers) keep the context-free line voice.
                $context = $item['context'] ?? null;
                $sessionName = is_array($context) && is_string($context['session_name'] ?? null)
                    ? $context['session_name']
                    : null;
                $lineKey = "notifications.body_batched_line_{$this->category->value}";

                if ($this->category === NotificationCategory::SessionAddedToCampaign
                    && is_string($sessionName) && $sessionName !== '') {
                    $lineKey .= '_with_session';
                }

                $mail->line(__($lineKey, [
                    'actor' => $actor,
                    'session' => $sessionName ?? '',
                ]));
            }
        } else {
            $mail->line($this->batchedCopy('body_batched_headline', $digest['total_actors'], [
                'actors' => $this->actorPrefix($digest),
                'count' => (string) $total,
            ]));
        }

        $mail->action(
            __('notifications.action_view_entity_type', ['entity_type' => ucfirst($this->entityTypeLabel())]),
            $this->actionUrlWithMarker($notifiable, $this->entityActionUrl($notifiable), category: $this->category->value),
        )->line($this->unsubscribeLine($notifiable, $this->category->value));

        // D287 continuity (M082 review): the unbatched session-added mail
        // carried a per-session iTIP REQUEST, and the batched digest keeps
        // that contract — one VEVENT per buffered session, addressed by its
        // own UID so each lands on its own calendar slot. IcsBuilder skips
        // undated/ownerless sessions, and a session deleted mid-window is
        // skipped here (the digest line already recorded the event).
        if ($this->category === NotificationCategory::SessionAddedToCampaign) {
            $this->attachSessionRequests($mail, $notifiable);
        }

        return $this->withPreheader($mail, 'notifications.preheader_batched', [
            'count' => (string) $total,
            'entity' => $this->entityName,
        ], $total);
    }

    /**
     * Batched push (S02/T02): verb-first body with the compact actor
     * prefix ("Sarah, Alex +3 joined your game"), the activity count +
     * entity name as the title, and the stable per-entity tag so
     * consecutive batches for the same entity REPLACE each other on the
     * tray instead of stacking (existing tag semantics, D286/D296 — with
     * renotify so a genuinely new burst re-alerts). PUSH_BADGE + the last
     * buffered activity's timestamp keep the tray render consistent with
     * every deadline-critical push.
     */
    public function toPush(User $notifiable): ?PushPayload
    {
        if (count($this->items) < self::MIN_INTERRUPT_ITEMS) {
            return null; // single-event batch keeps the pre-M082 quiet surface — see MIN_INTERRUPT_ITEMS
        }

        $digest = NotificationBatch::digestVariables($this->items);

        return $this->pushPayload(
            trans_choice('notifications.push_title_batched', count($this->items), [
                'count' => count($this->items),
                'entity' => $this->entityName,
            ]),
            $this->batchedCopy('push_body_batched', $digest['total_actors'], [
                'actors' => $this->actorPrefix($digest),
            ]),
            $this->actionUrlWithMarker($notifiable, $this->entityActionUrl($notifiable), category: $this->category->value),
            "batched-{$this->category->value}-{$this->entityType}-{$this->entityId}",
            timestamp: $this->latestOccurredAt()->getTimestamp(),
            badge: self::PUSH_BADGE,
            renotify: true,
        );
    }

    /**
     * Mirrors the push copy as a Discord embed (D130: Discord mirrors
     * push) with the entity as the author line — the digest is "about"
     * the entity, so the byline names and deep-links it while title and
     * description carry the exact push voice.
     */
    public function toDiscord(User $notifiable): ?DiscordWebhookPayload
    {
        if (count($this->items) < self::MIN_INTERRUPT_ITEMS) {
            return null; // mirrors the push floor (D130) — single-event batches stay in-app + mail only
        }

        $digest = NotificationBatch::digestVariables($this->items);
        $entityUrl = $this->entityActionUrl($notifiable);

        return $this->discordEmbed(
            trans_choice('notifications.push_title_batched', count($this->items), [
                'count' => count($this->items),
                'entity' => $this->entityName,
            ]),
            $entityUrl,
            $this->batchedCopy('push_body_batched', $digest['total_actors'], [
                'actors' => $this->actorPrefix($digest),
            ]),
            author: ['name' => $this->entityName, 'url' => $entityUrl],
        );
    }

    /**
     * Unique actor names in append order (first occurrence wins) — the
     * deterministic sequence the in-app row lists.
     *
     * @return list<string>
     */
    public function actorNames(): array
    {
        return array_column($this->distinctActors(), 'name');
    }

    /**
     * Actor ids aligned by position with actorNames() — the pairing the
     * bell's grouped display links to profiles through (id-less snapshots
     * yield null, the same unlinked-name fallback unbatched rows use).
     *
     * @return list<string|null>
     */
    public function actorIds(): array
    {
        return array_column($this->distinctActors(), 'id');
    }

    /**
     * Distinct actors in append order, keyed by actor id (name fallback
     * for id-less snapshots) with first occurrence fixing both the display
     * name and the id — the SAME keying NotificationBatch::digestVariables
     * uses, so the bell's names, the push prefix, and the mail counts can
     * never disagree about how many actors a burst contains.
     *
     * @return list<array{id: string|null, name: string}>
     */
    private function distinctActors(): array
    {
        $actors = [];
        foreach ($this->items as $item) {
            $name = $item['actor_name'] ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }

            $id = $item['actor_id'] ?? null;
            $id = is_string($id) && $id !== '' ? $id : null;

            $key = $id ?? 'name:'.$name;

            if (! array_key_exists($key, $actors)) {
                $actors[$key] = ['id' => $id, 'name' => $name];
            }
        }

        return array_values($actors);
    }

    /**
     * Re-attach the per-session iTIP REQUESTs for a flushed session burst
     * (D287 continuity, M082 review): one VEVENT per buffered session,
     * rehydrated from the item's session_id snapshot — the invitation must
     * land on the session's calendar slot (campaign-{id}-session-{gameId}
     * UID), exactly as the unbatched mail's attachment did.
     */
    private function attachSessionRequests(MailMessage $mail, User $notifiable): void
    {
        foreach ($this->items as $item) {
            $context = $item['context'] ?? null;
            $sessionId = is_array($context) && is_string($context['session_id'] ?? null)
                ? $context['session_id']
                : null;

            if (! is_string($sessionId) || $sessionId === '') {
                continue; // legacy in-flight snapshot without session identity — nothing to invite for
            }

            $session = Game::query()->find($sessionId);

            if ($session === null) {
                continue; // deleted mid-window — the digest line already recorded the event
            }

            $actionUrl = $item['action_url'] ?? null;

            $this->attachIcsRequest(
                $mail,
                $session,
                $notifiable,
                is_string($actionUrl) && $actionUrl !== '' ? $actionUrl : $this->actionUrl,
            );
        }
    }

    // ── Shared copy derivation ────────────────────────────────────────

    /**
     * The batch-level action target every channel links (M082 review):
     * the buffered first item's action URL, EXCEPT the campaign-hosted
     * session arm — a session_added_to_campaign burst is ABOUT the
     * campaign (subject, CTA label "View Campaign", bell entity link all
     * name it), so its batch surfaces must land on the campaign's own
     * page, not the first buffered session's. The recipients are campaign
     * MEMBERS (AddSessionToCampaign notifies approved participants, not
     * the owner), so the target is the member-visible campaigns.detail
     * page — not the owner dashboard (campaigns.show) that host-facing
     * classes link. Per-item session URLs stay untouched in the items:
     * the per-session ICS REQUESTs correctly deep-link each session's own
     * page (D287 continuity).
     */
    private function entityActionUrl(User $notifiable): string
    {
        if ($this->category === NotificationCategory::SessionAddedToCampaign && $this->entityType === 'campaign') {
            return route('campaigns.detail', [
                'locale' => $this->resolveLocale($notifiable),
                'id' => $this->entityId,
            ]);
        }

        return $this->actionUrl;
    }

    /**
     * Compact actor prefix for push/mail digest surfaces: the first
     * PUSH_ACTOR_NAMES display actors joined by ", ", plus "+N" naming
     * the remaining distinct actors ("Sarah, Alex +3" — the demo shape).
     * Distinct-actor derivation comes from the shared
     * NotificationBatch::digestVariables(), so every channel presents the
     * SAME names and counts.
     *
     * @param  array{total_activities: int, total_actors: int, display_actors: array<int, array{id: string, name: string}>, activities_preview: array<int, array<string, mixed>>}  $digest
     */
    private function actorPrefix(array $digest): string
    {
        $names = array_slice(array_column($digest['display_actors'], 'name'), 0, self::PUSH_ACTOR_NAMES);

        $prefix = implode(', ', $names);
        $remaining = $digest['total_actors'] - count($names);

        return $remaining > 0 ? $prefix.' +'.$remaining : $prefix;
    }

    /**
     * Resolve one per-category copy key with German-style subject/verb
     * agreement: two or more distinct actors select the "_plural" variant
     * first ("Sarah, Alex sind beigetreten", not "…ist beigetreten"),
     * falling back to the singular key when the locale defines no plural
     * variant (English verbs are uninflected) — the same plural-optional
     * convention NotificationQueryService::resolveVerb() established for
     * the bell's verb keys.
     *
     * @param  array<string, string>  $replace
     */
    private function batchedCopy(string $prefix, int $totalActors, array $replace): string
    {
        $base = "notifications.{$prefix}_{$this->category->value}";

        if ($totalActors >= 2) {
            $pluralKey = $base.'_plural';
            $plural = __($pluralKey, $replace);

            if ($plural !== $pluralKey) {
                return $plural;
            }
        }

        return __($base, $replace);
    }

    /**
     * The last buffered activity's instant — the Web Push display
     * timestamp (epoch seconds), so the tray shows "sent at" the moment
     * the burst's newest event happened rather than the flush render
     * time. Falls back to now() only for a payload with no parseable
     * occurred_at (the buffer contract guarantees one).
     */
    private function latestOccurredAt(): Carbon
    {
        for ($i = count($this->items) - 1; $i >= 0; $i--) {
            $raw = $this->items[$i]['occurred_at'] ?? null;

            if (is_string($raw) && $raw !== '') {
                return Carbon::parse($raw);
            }
        }

        return Carbon::now();
    }

    /**
     * Human-readable entity type label for the current locale (the shared
     * game/campaign vocabulary the unbatched classes use).
     */
    private function entityTypeLabel(): string
    {
        return match ($this->entityType) {
            'campaign' => __('notifications.entity_type_campaign'),
            default => __('notifications.entity_type_game'),
        };
    }
}
