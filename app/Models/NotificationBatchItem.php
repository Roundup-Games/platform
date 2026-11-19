<?php

namespace App\Models;

use App\Models\Concerns\HasPlatformUuid;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One buffered event inside a NotificationBatch (M082/S01, D296).
 *
 * Immutable append-only rows: payload is the serialized event snapshot
 * (actor name/id, entity name/type, event context, created_at) captured
 * at buffer time, exactly what the batched rendering (S02) replays. Rows
 * carry created_at only — a snapshot is never edited, so there is no
 * updated_at column.
 *
 * @property string $id
 * @property string $batch_id
 * @property array<string, mixed> $payload
 * @property Carbon|null $created_at
 */
class NotificationBatchItem extends Model
{
    use HasPlatformUuid;

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $fillable = [
        'batch_id',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    // ── Relationships ──────────────────────────────────

    /** @return BelongsTo<NotificationBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(NotificationBatch::class);
    }
}
