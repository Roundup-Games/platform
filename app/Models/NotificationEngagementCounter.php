<?php

namespace App\Models;

use App\Services\NotificationEngagementCounters;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * One daily aggregate counter row for notification engagement telemetry
 * (M080/S03, D284): how many events of a given kind fired for a
 * notification category on a given UTC day, per channel.
 *
 * Rows are natural-keyed on (date, category, channel, event) — there is
 * deliberately NO per-event granularity here. The single write path is
 * {@see NotificationEngagementCounters::incrementFor()},
 * an atomic upsert on the composite unique index; this model exists for
 * reads (future dashboard work) and test assertions, not for creating
 * rows.
 *
 * @property int $id
 * @property Carbon $date
 * @property string $category
 * @property string $channel
 * @property string $event
 * @property int $count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotificationEngagementCounter extends Model
{
    protected $fillable = [
        'date',
        'category',
        'channel',
        'event',
        'count',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'count' => 'integer',
        ];
    }
}
