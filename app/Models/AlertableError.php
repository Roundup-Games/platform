<?php

namespace App\Models;

use App\Logging\AlertableErrorTap;
use App\Models\Concerns\HasPlatformUuid;
use Carbon\Carbon;
use Database\Factories\AlertableErrorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One distinct alertable log message (observability 2026-11-03), upserted
 * by {@see AlertableErrorTap} for every record the default
 * stack channel emits at WARNING or above. message_hash (sha256 of the
 * message text) is the row identity; occurrences counts recorded sightings,
 * bracketed by first_seen_at/last_seen_at.
 *
 * Paging watermark: last_notified_at (set by observability:scan and
 * observability:digest). Retirement: resolved_at (scan resolves stale
 * non-critical noise after 7 days).
 *
 * @property string $id
 * @property string $message_hash
 * @property string $message
 * @property string $level
 * @property string $channel
 * @property array<string, mixed>|null $context
 * @property int $occurrences
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $last_notified_at
 * @property Carbon|null $resolved_at
 */
class AlertableError extends Model
{
    /** @use HasFactory<AlertableErrorFactory> */
    use HasFactory, HasPlatformUuid;

    /**
     * No created_at/updated_at columns: first_seen_at/last_seen_at already
     * bracket the row's lifecycle, and the tap's upsert path would need an
     * extra update clause pair for timestamps nothing reads.
     */
    public $timestamps = false;

    protected $fillable = [
        'message_hash',
        'message',
        'level',
        'channel',
        'context',
        'occurrences',
        'first_seen_at',
        'last_seen_at',
        'last_notified_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurrences' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_notified_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
