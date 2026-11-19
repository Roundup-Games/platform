<?php

namespace App\Models;

use App\Models\Concerns\HasPlatformUuid;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One buffered notification burst per (recipient, batch_key) — the
 * batch-on-write defer window (M082/S01, D296).
 *
 * An open batch (processed_at IS NULL) collects NotificationBatchItem
 * snapshots while its 5-minute window runs. FlushNotificationBatch (T03)
 * renders the items into one BatchedNotification and calls markProcessed()
 * BEFORE dispatching, so processed_at is the idempotency marker: a re-run
 * flush (redis ephemerality → every-minute sweep recovery) sees it set and
 * returns without re-sending. Marking a batch processed also releases the
 * partial unique index (notification_batches_open_batch_unique), letting
 * the next burst open a fresh window under the same key.
 *
 * @property string $id
 * @property string $recipient_type
 * @property string $recipient_id
 * @property string $category
 * @property string $batch_key
 * @property Carbon $closes_at
 * @property Carbon|null $processed_at
 * @property int $flush_attempts
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotificationBatch extends Model
{
    use HasPlatformUuid, Prunable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'category',
        'batch_key',
        'closes_at',
        'processed_at',
        'flush_attempts',
    ];

    protected function casts(): array
    {
        return [
            'closes_at' => 'datetime',
            'processed_at' => 'datetime',
            'flush_attempts' => 'integer',
        ];
    }

    // ── Relationships ──────────────────────────────────

    /** @return MorphTo<Model, $this> */
    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Buffered event snapshots, in append order.
     *
     * @return HasMany<NotificationBatchItem, $this>
     */
    public function items(): HasMany
    {
        // uuidv7 ids are time-ordered (HasPlatformUuid), so id order is
        // append order — the deterministic item sequence the batched
        // rendering (S02) presents as "actor_names" et al.
        return $this->hasMany(NotificationBatchItem::class, 'batch_id')->orderBy('id');
    }

    // ── Digest derivation (S02/T01) ────────────────────

    /** Digest display cap: distinct actors a digest NAMES before collapsing to a count (push shows [:2] + "+N", in-app row lists the display prefix). */
    public const int DIGEST_DISPLAY_ACTORS = 3;

    /** Digest preview cap: buffered payloads the activities preview exposes — aligned with the buffer path's BATCH_MAX_ITEMS so a maximally-sized batch previews in full. */
    public const int DIGEST_PREVIEW_ITEMS = 10;

    /** Retention horizon for the daily model:prune sweep: a processed batch (and its cascaded items) is buffer state, not an audit ledger — the user-facing artifact lives in notifications and the analytics in the D284 counters. Only PROCESSED rows are ever pruned; unprocessed rows (including sweep dead-letters past the attempt bound) are the live window or its forensic record and are never touched. */
    public const int PRUNE_AFTER_DAYS = 30;

    /**
     * Derive the shared digest variables every batched channel renderer
     * presents (M082/S02 — the Knock digest-variable shape): activity and
     * distinct-actor totals, the capped actor display prefix in arrival
     * order, and a bounded payload preview.
     *
     * Pure derivation over payloads in arrival order (items() orders by
     * time-ordered uuidv7 id), so the flush path and BatchedNotification
     * (whose constructor carries the same payloads) feed every channel
     * renderer the SAME digest numbers and names. Static by design: the
     * renderers hold payloads, not the batch model.
     *
     * Actor identity is actor_id when present, falling back to actor_name
     * for id-less snapshots, so one actor's repeats collapse to a single
     * entry regardless of shape; the FIRST arrival fixes each actor's
     * display name (the same first-occurrence rule the minimal S01
     * renderer's actorNames() followed).
     *
     * @param  array<int, array<string, mixed>>  $payloads  buffered item payloads in arrival order (the snapshot contract documented on BatchedNotification)
     * @return array{total_activities: int<0, max>, total_actors: int<0, max>, display_actors: array<int, array{id: string, name: string}>, activities_preview: array<int, array<string, mixed>>}
     */
    public static function digestVariables(array $payloads): array
    {
        /** @var array<string, array{id: string, name: string}> $actors */
        $actors = [];

        foreach ($payloads as $payload) {
            $actorId = is_string($payload['actor_id'] ?? null) ? $payload['actor_id'] : '';
            $actorName = is_string($payload['actor_name'] ?? null) ? $payload['actor_name'] : '';

            $key = $actorId !== '' ? $actorId : 'name:'.$actorName;

            if (! array_key_exists($key, $actors)) {
                $actors[$key] = ['id' => $actorId, 'name' => $actorName];
            }
        }

        return [
            'total_activities' => count($payloads),
            'total_actors' => count($actors),
            'display_actors' => array_slice(array_values($actors), 0, self::DIGEST_DISPLAY_ACTORS),
            'activities_preview' => array_slice($payloads, 0, self::DIGEST_PREVIEW_ITEMS),
        ];
    }

    // ── Scopes ─────────────────────────────────────────

    /**
     * Unprocessed batches — REGARDLESS of closes_at.
     *
     * The buffer-append path (NotificationService, T04) uses this under
     * lockForUpdate: an event arriving after closes_at but before the
     * flush ran must join the imminent batch. Matching on processed_at
     * alone (not closes_at > now) is what makes the late append safe.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('processed_at');
    }

    /**
     * Unprocessed batches whose window has closed — the sweep's
     * candidates (notifications:flush-batches, T03).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeClosedUnprocessed(Builder $query): Builder
    {
        return $query
            ->whereNull('processed_at')
            ->where('closes_at', '<=', now());
    }

    // ── Lifecycle ──────────────────────────────────────

    /**
     * Stamp the batch flushed. Idempotent: an already-processed batch is
     * left untouched (original timestamp preserved) and returns false.
     */
    public function markProcessed(): bool
    {
        if ($this->processed_at !== null) {
            return false;
        }

        $this->processed_at = now();
        $this->save();

        return true;
    }

    /**
     * Daily retention scope (model:prune, 05:45): processed batches
     * older than PRUNE_AFTER_DAYS — deleting a batch cascades its items,
     * closing the buffer's lifecycle end-state. Unprocessed batches are
     * NEVER pruned, however old: an open row is the live window, and a
     * closed unprocessed row (a sweep dead-letter past its attempt
     * bound) is the forensic record the notification_batch.* logs and
     * the alerting follow-up read.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->whereNotNull('processed_at')
            ->where('processed_at', '<=', now()->subDays(self::PRUNE_AFTER_DAYS));
    }
}
