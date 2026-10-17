<?php

namespace App\Models;

use App\Enums\ScheduledTaskRunStatus;
use App\Models\Concerns\HasPlatformUuid;
use Carbon\Carbon;
use Database\Factories\ScheduledTaskRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * One dispatched run of a scheduled task, written by the framework
 * scheduling events (see RecordScheduledTaskRun). The schedule says when
 * work is DUE; these rows say whether it actually RAN and how it ended.
 *
 * @property ScheduledTaskRunStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int|null $runtime_ms
 * @property string|null $error
 */
class ScheduledTaskRun extends Model
{
    /** @use HasFactory<ScheduledTaskRunFactory> */
    use HasFactory, HasPlatformUuid, Prunable;

    protected $fillable = [
        'task_key',
        'status',
        'started_at',
        'finished_at',
        'runtime_ms',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => ScheduledTaskRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'runtime_ms' => 'integer',
        ];
    }

    /**
     * Latest run per task key, keyed by task key. Bounded corpus (30-day
     * retention, a few dozen task keys) so a single ordered fetch + PHP
     * dedupe beats a window-function scope for clarity.
     *
     * @return array<string, self>
     */
    public static function latestPerTask(): array
    {
        return static::query()
            ->orderByDesc('started_at')
            ->get()
            ->unique(fn (self $run): string => $run->task_key)
            ->keyBy(fn (self $run): string => $run->task_key)
            ->all();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWhereTask(Builder $query, string $taskKey): Builder
    {
        return $query->where('task_key', $taskKey);
    }

    /**
     * Retention: 30 days of history, and "running" rows older than 24h —
     * zombies from a scheduler/worker that died mid-run, which will never
     * receive their Finished/Failed event.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()
            ->where('created_at', '<', now()->subDays(30))
            ->orWhere(fn (Builder $query) => $query
                ->where('status', ScheduledTaskRunStatus::Running)
                ->where('started_at', '<', now()->subDay()));
    }
}
