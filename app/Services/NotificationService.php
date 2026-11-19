<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Jobs\FlushNotificationBatch;
use App\Models\EmailSuppression;
use App\Models\NotificationBatch;
use App\Models\User;
use App\Notifications\BaseNotification;
use App\Notifications\BatchedNotification;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\PushChannel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Central dispatch service for all notifications.
 *
 * Wraps Laravel's Notifiable trait with:
 *   - Per-category channel resolution from notification_settings JSON.
 *   - Block-list filtering: suppresses notifications from blocked actors.
 *   - Structured logging of every dispatch (sent + skipped).
 *   - Error resilience: dispatch failures are logged, never thrown.
 */
class NotificationService
{
    /** Defer-window length (D296): the first buffered event of a burst sets closes_at now + this. Public so the NotificationBatchingPage policy matrix quotes the enforced number (M082/S03). */
    public const int BATCH_WINDOW_MINUTES = 5;

    /** MaxSize guardrail (D296): a batch reaching this item count flushes immediately instead of waiting out its window. Public so the NotificationBatchingPage policy matrix quotes the enforced number (M082/S03). */
    public const int BATCH_MAX_ITEMS = 10;

    /** Bound on retries against a concurrent batch creation racing the partial unique index. */
    private const int BATCH_CREATE_ATTEMPTS = 3;

    public function __construct(
        private readonly PostHogAnalytics $posthogAnalytics,
        private readonly NotificationEngagementCounters $counters,
    ) {}

    /**
     * Send a notification to a user, respecting their channel preferences
     * and block list.
     *
     * If the notification exposes a getActor() method, the service checks
     * whether the notifiable has blocked that actor and silently skips
     * dispatch if so.
     *
     * Channels are resolved from the user's notification_settings JSON
     * for the given category. Falls back to defaults when the settings
     * are null or malformed.
     */
    public function send(User $notifiable, Notification $notification, NotificationCategory $category): void
    {
        $notificationType = get_class($notification);
        $categoryValue = $category->value;

        try {
            // ── Block-list check ──
            if (method_exists($notification, 'getActor')) {
                $actor = $notification->getActor();
                if ($actor instanceof User && $notifiable->hasBlocked($actor)) {
                    Log::info('notification.dispatch_skipped', [
                        'reason' => 'blocked_actor',
                        'notifiable_id' => $notifiable->id,
                        'actor_id' => $actor->id,
                        'notification_type' => $notificationType,
                        'category' => $categoryValue,
                    ]);

                    return;
                }
            }

            // ── Channel resolution ──
            // resolveChannels() returns the channels the recipient has ENABLED
            // for this category (name => class). Intersect that with the channels
            // the notification itself SUPPORTS (via()) so a database-only
            // notification never sends mail even if the user enabled it, and a
            // user who disabled mail never receives it even if the notification
            // supports it.
            $dispatchChannels = $this->resolveChannels($notifiable, $category);

            if ($notification instanceof BaseNotification) {
                $supportedChannels = $notification->via($notifiable);

                $dispatchChannels = array_filter(
                    $dispatchChannels,
                    fn (string $channel) => in_array($channel, $supportedChannels, true),
                );

                // Push the resolved intersection onto the instance so via() —
                // read by Laravel's queued dispatcher at enqueue time — returns
                // only these channels. This is what makes "mail: false" actually
                // suppress mail across the queue boundary.
                if ($dispatchChannels !== []) {
                    $notification->setResolvedChannels(array_values($dispatchChannels));
                }
            }

            if ($dispatchChannels === []) {
                Log::info('notification.dispatch_skipped', [
                    'reason' => 'all_channels_disabled',
                    'notifiable_id' => $notifiable->id,
                    'notification_type' => $notificationType,
                    'category' => $categoryValue,
                ]);

                return;
            }

            // ── Burst defer window (D296, M082/S01) ──
            // Host-side bursty categories (ParticipantJoined, NewApplication,
            // SessionAddedToCampaign) buffer per (recipient, category, entity)
            // and land as ONE BatchedNotification at window close instead of
            // pinging the host per event. The re-entry guard has two arms:
            // BatchedNotification flows back through send() at flush time and
            // must NEVER re-buffer — the instanceof exclusion is the first,
            // its inherited null batchKey() default the second.
            // Placement matters: block-list and channel resolution already
            // ran, so blocked actors and all-channels-disabled recipients
            // buffer nothing (their gates apply exactly as they would have
            // to an individual dispatch).
            if (
                $notification instanceof BaseNotification
                && ! $notification instanceof BatchedNotification
                && $category->isBatchable()
                && ($batchKey = $notification->batchKey($notifiable)) !== null
            ) {
                $this->bufferForDeferWindow($notifiable, $notification, $category, $batchKey);

                return;
            }

            // ── Dispatch with recipient locale ──
            // User implements HasLocalePreference, so Laravel's notification sender
            // automatically resolves the correct locale via preferredLocale() before
            // calling toMail/toDatabase/toPush. No global mutation needed.
            //
            // Uses notify() (queued) — BaseNotification implements ShouldQueue so
            // all notifications are processed asynchronously by Horizon workers.
            // In tests, QUEUE_CONNECTION=sync makes this behave identically to notifyNow().
            $notifiable->notify($notification);

            Log::info('notification.dispatched', [
                'notifiable_id' => $notifiable->id,
                'notification_type' => $notificationType,
                'category' => $categoryValue,
                'channels' => array_keys($dispatchChannels),
            ]);

            // Batch-vs-immediate telemetry (D296, the S03 matrix page's
            // live columns): every event that dispatches now instead of
            // buffering counts as immediate. BatchedNotification is excluded
            // — its dispatch IS the batch path's terminal event and the flush
            // job already counted the batch as 'flushed'; counting both would
            // corrupt the batched-vs-immediate ratio the matrix reads.
            if (! $notification instanceof BatchedNotification) {
                $this->countBatchEvent($categoryValue, 'dispatch_immediate');
            }

            // Retention analytics: capture that a notification was sent to this
            // user, with its category and channels. Enables correlation of
            // 'received attendance nudge' with attendance outcomes.
            $this->posthogAnalytics->capture(
                $notifiable,
                'notification.sent',
                [
                    'category' => $categoryValue,
                    'channels' => array_keys($dispatchChannels),
                ],
            );
        } catch (\Throwable $e) {
            Log::error('notification.dispatch_failed', [
                'notifiable_id' => $notifiable->id,
                'notification_type' => $notificationType,
                'category' => $categoryValue,
                'error' => $e->getMessage(),
            ]);

            // Retention analytics: capture notification dispatch failures for
            // channel-health monitoring (e.g. push tokens going stale).
            $this->posthogAnalytics->capture(
                $notifiable,
                'notification.failed',
                ['category' => $categoryValue, 'reason' => 'dispatch_error'],
            );
        }
    }

    // ── Burst defer window (D296, M082/S01) ────────────────────────────

    /**
     * Buffer one bursty event instead of dispatching it immediately —
     * the write half of the batch-on-write defer window.
     *
     * Appends the event's serialized snapshot to the recipient's open
     * (recipient, category, entity) batch, creating the batch (and its
     * delayed flush job) when the burst starts. The append is a single
     * transaction under lockForUpdate on the batch row, so it can never
     * interleave with FlushNotificationBatch's mark-first transaction:
     * either the item joins before the mark (it flushes now) or the flush
     * already won (a fresh batch opens below). An event arriving while
     * the window is closed but unflushed appends to the imminent flush —
     * the open() predicate is processed_at IS NULL only, NOT
     * closes_at > now, precisely so the late arrival can neither violate
     * the open-batch unique index nor be silently lost to send()'s catch.
     *
     * Dispatch happens AFTER the append transaction returns (never via
     * DB::afterCommit: the test suite wraps every test in a never-committing
     * DatabaseTransactions wrapper, which would defer those callbacks
     * forever and hide the flush from tests). On the sync test connection
     * the delayed job fires inline right after the transaction — so flows
     * under test deliver their BatchedNotification synchronously; on redis
     * the delayUntil() schedule holds until closes_at regardless.
     */
    private function bufferForDeferWindow(User $notifiable, BaseNotification $notification, NotificationCategory $category, string $batchKey): void
    {
        $payload = $this->deferWindowPayload($notification, $notifiable, $category, $batchKey);

        [$batch, $created, $itemCount] = $this->appendToDeferWindow($notifiable, $category, $batchKey, $payload);

        if ($created) {
            // The fast path (D296): flush at window close. The every-minute
            // notifications:flush-batches sweep is the durable second path
            // should this delayed job be lost (redis ephemerality).
            // (delay() takes the DateTimeInterface directly — this Laravel
            // generation's Queueable has no delayUntil().)
            FlushNotificationBatch::dispatch($batch->id)->delay($batch->closes_at);
        } elseif ($itemCount >= self::BATCH_MAX_ITEMS) {
            // MaxSize guardrail (D296): the burst outgrew the window — flush
            // now instead of accumulating further. The creation-time delayed
            // job still fires at closes_at and no-ops on the processed batch
            // (processed_at idempotency).
            FlushNotificationBatch::dispatch($batch->id);
        }

        Log::info('notification_batch.event_buffered', [
            'batch_id' => $batch->id,
            'batch_key' => $batchKey,
            'category' => $category->value,
            'notifiable_id' => $notifiable->id,
            'item_count' => $itemCount,
            'created' => $created,
        ]);

        $this->countBatchEvent($category->value, 'event_buffered');
    }

    /**
     * Append one item to the open (recipient, batch_key) batch, creating
     * the batch when there is none, inside one locked transaction.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: NotificationBatch, 1: bool, 2: int} [batch, created, itemCount]
     */
    private function appendToDeferWindow(User $notifiable, NotificationCategory $category, string $batchKey, array $payload): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                /** @var array{0: NotificationBatch, 1: bool, 2: int<0, max>} $appended */
                $appended = DB::transaction(function () use ($notifiable, $category, $batchKey, $payload): array {
                    // open() + lockForUpdate: processed_at IS NULL only. A
                    // flush that committed first makes Postgres re-evaluate
                    // the locked row against the predicate (READ COMMITTED),
                    // so the race loser sees no row and opens a fresh batch —
                    // the belt-and-braces processed_at re-check below covers
                    // the same race read off a stale instance.
                    $batch = NotificationBatch::query()
                        ->open()
                        ->where('recipient_type', $notifiable->getMorphClass())
                        ->where('recipient_id', $notifiable->getKey())
                        ->where('batch_key', $batchKey)
                        ->lockForUpdate()
                        ->first();

                    $created = false;

                    if ($batch !== null && $batch->processed_at !== null) {
                        $batch = null;
                    }

                    if ($batch === null) {
                        $batch = NotificationBatch::query()->create([
                            'recipient_type' => $notifiable->getMorphClass(),
                            'recipient_id' => $notifiable->getKey(),
                            'category' => $category->value,
                            'batch_key' => $batchKey,
                            'closes_at' => now()->addMinutes(self::BATCH_WINDOW_MINUTES),
                        ]);
                        $created = true;
                    }

                    $batch->items()->create(['payload' => $payload]);

                    return [$batch, $created, $batch->items()->count()];
                });

                return $appended;
            } catch (UniqueConstraintViolationException $exception) {
                // Two appends raced the batch creation: both found no open
                // row and both inserted — the loser hits the open-batch
                // partial unique index. Retry: the winner's row is committed
                // by now, so the next attempt appends to it instead of losing
                // the event to send()'s catch (the "silently lost event"
                // failure mode D296 rules out).
                if ($attempt >= self::BATCH_CREATE_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Serialize one buffered event — the item payload contract documented
     * on BatchedNotification and enforced by FlushNotificationBatch.
     *
     * Entity identity is derived from the notification's toDatabase()
     * payload by matching the batch key's entity uuid: the direct shape
     * (joined/application carry entity_type/entity_id/entity_name for the
     * entity that keys the burst) or the campaign-hosted shape
     * (session_added batches on the parent campaign, whose identity rides
     * campaign_id/campaign_name). A mismatch means a batchable class broke
     * the snapshot contract — throw loudly (send()'s catch logs
     * dispatch_failed) rather than flushing garbage later.
     *
     * @return array<string, mixed>
     */
    private function deferWindowPayload(BaseNotification $notification, User $notifiable, NotificationCategory $category, string $batchKey): array
    {
        $separator = strpos($batchKey, ':');

        if ($separator === false || $separator === 0 || $separator === strlen($batchKey) - 1) {
            throw new \UnexpectedValueException("Batch key [{$batchKey}] must be 'category:entityUuid'.");
        }

        $entityId = substr($batchKey, $separator + 1);

        /** @var array<string, mixed> $data */
        $data = $notification->toDatabase($notifiable);

        if (($data['entity_id'] ?? null) === $entityId) {
            $entityType = $data['entity_type'] ?? null;
            $entityName = $data['entity_name'] ?? null;
        } elseif (($data['campaign_id'] ?? null) === $entityId) {
            $entityType = 'campaign';
            $entityName = $data['campaign_name'] ?? null;
        } else {
            throw new \UnexpectedValueException(
                'Batched payload contract broken: toDatabase() identifies neither an entity nor a campaign under batch key ['.$batchKey.'].',
            );
        }

        $actionUrl = $data['action_url'] ?? null;

        if (! is_string($entityType) || $entityType === ''
            || ! is_string($entityName) || $entityName === ''
            || ! is_string($actionUrl) || $actionUrl === '') {
            throw new \UnexpectedValueException(
                'Batched payload contract broken for batch key ['.$batchKey.']: entity_type, entity_name and action_url must be non-empty strings.',
            );
        }

        $actor = $notification->getActor();

        return [
            'actor_id' => $actor->id ?? '',
            'actor_name' => $actor->name ?? '',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_name' => $entityName,
            'event' => $category->value,
            // Event-specific extras from the notification's batchItemContext()
            // hook (empty for the v1 set except SessionAddedToCampaign's
            // session identity, which the batched mail needs for per-session
            // ICS invitations and session-named digest lines).
            'context' => $notification->batchItemContext($notifiable),
            'action_url' => $actionUrl,
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Batch-channel telemetry bump (D284 aggregate). Telemetry must never
     * break the delivery path: a counter DB failure is logged and swallowed
     * — the same log-and-continue contract FlushNotificationBatch and the
     * channel renderers follow.
     */
    private function countBatchEvent(string $categoryValue, string $event): void
    {
        try {
            $this->counters->incrementFor($categoryValue, 'batch', $event);
        } catch (\Throwable $e) {
            Log::warning('notification_batch.counter_failed', [
                'category' => $categoryValue,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark all unread notifications of a specific type (and optional entity)
     * as read for a user.
     *
     * Used by accept/decline invitation flows to clear the related
     * in-app notification.
     */
    public function markReadByType(User $user, string $notificationType, int|string|null $entityId = null, ?string $dataKey = null): void
    {
        try {
            $query = $user->unreadNotifications()->where('type', $notificationType);

            if ($entityId !== null) {
                $key = $dataKey ?? 'entity_id';
                // Whitelist: only allow known JSON data keys to prevent injection
                $allowedKeys = ['entity_id', 'game_id', 'campaign_id', 'team_id', 'participant_id', 'type'];
                if (! in_array($key, $allowedKeys, true)) {
                    Log::warning('notification.mark_read_invalid_key', [
                        'key' => $key,
                        'user_id' => $user->id,
                    ]);

                    return;
                }
                $query->where("data->{$key}", $entityId);
            }

            $count = $query->update(['read_at' => now()]);

            if ($count > 0) {
                Log::debug('notification.marked_read_by_type', [
                    'user_id' => $user->id,
                    'notification_type' => $notificationType,
                    'entity_id' => $entityId,
                    'count' => $count,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('notification.mark_read_failed', [
                'user_id' => $user->id,
                'notification_type' => $notificationType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve which notification channels are enabled for a user
     * and category combination.
     *
     * Reads the user's notification_settings JSON column and resolves each
     * channel independently: a channel key that is explicitly present (even if
     * false) is honoured, while a missing key falls back to that category's
     * default. The whole category also falls back to defaults when the blob is
     * null, the category is absent, or its value is malformed.
     *
     * This per-channel fallback keeps legacy/partial rows (e.g. created before
     * push existed, or for categories added after the user last saved prefs)
     * behaving as intended instead of silently treating a missing channel as
     * disabled.
     *
     * Maps channel names to Laravel channel class strings:
     *   - 'database' → DatabaseChannel::class
     *   - 'mail'     → MailChannel::class
     *   - 'push'     → PushChannel::class
     *
     * @return array<string, string> Channel name => channel class
     */
    public function resolveChannels(User $user, NotificationCategory $category): array
    {
        /** @var array<string, mixed>|null $settings */
        $settings = $user->notification_settings;
        $categoryKey = $category->value;
        $defaults = NotificationCategory::defaultSettings();

        $categoryDefaults = $defaults[$categoryKey] ?? ['database' => true, 'mail' => false, 'push' => false, 'discord' => false];

        // Resolve the stored per-category settings. $stored stays null when the
        // settings blob is null, the category is absent, or its value is malformed
        // — in all those cases we fall back to the full category default below.
        $stored = null;
        if (is_array($settings) && array_key_exists($categoryKey, $settings)) {
            if (is_array($settings[$categoryKey])) {
                $stored = $settings[$categoryKey];
            } else {
                // Key exists but value is malformed — fall back to defaults
                Log::warning('notification.malformed_settings', [
                    'user_id' => $user->id,
                    'category' => $categoryKey,
                    'raw_value' => $settings[$categoryKey],
                ]);
            }
        }

        // Map enabled booleans to channel class strings. Discord (D118) joins
        // the matrix as a fourth channel — its payload is auto-derived from
        // toDatabase() by DiscordChannel, so it routes through the same
        // enabled/supported intersection as the other channels.
        $channelMap = [
            'database' => DatabaseChannel::class,
            'mail' => MailChannel::class,
            'push' => PushChannel::class,
            'discord' => DiscordChannel::class,
        ];

        // Per-channel resolution: use the stored value when the channel key is
        // explicitly present (even if false), otherwise fall back to the category
        // default. This keeps behaviour correct for legacy/partial rows (e.g.
        // created before push existed, or for categories added after the user
        // last saved their preferences) instead of silently treating a missing
        // channel key as "disabled".
        $resolved = [];
        foreach ($channelMap as $name => $class) {
            $enabled = $stored !== null
                ? (bool) ($stored[$name] ?? $categoryDefaults[$name])
                : $categoryDefaults[$name];

            if ($enabled) {
                $resolved[$name] = $class;
            }
        }

        // Delivery hygiene (M061): never hand the mail channel to Resend for an
        // address it has already told us is undeliverable (hard bounce) or that
        // complained (spam). The suppression check is a single indexed lookup on
        // the lowercased email; dropping mail here protects sender reputation
        // and complies with the recipient's spam-complaint opt-out.
        if (isset($resolved['mail']) && EmailSuppression::isSuppressed($user->email)) {
            unset($resolved['mail']);

            Log::info('notification.mail_suppressed', [
                'user_id' => $user->id,
                'category' => $categoryKey,
            ]);
        }

        return $resolved;
    }
}
