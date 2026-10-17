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
use Illuminate\Database\Query\Expression;

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
     * Latest run per task key, keyed by task key, newest first.
     *
     * The corpus grows to ~90k rows at steady state (the every-minute
     * escalated automations alone write ~43k rows per 30-day retention
     * window), so this must never hydrate the full table — Postgres
     * DISTINCT ON returns exactly one row per task inside the database.
     * Called on every Scheduled Tasks page render and every health
     * summary recompute.
     *
     * @return array<string, self>
     */
    public static function latestPerTask(): array
    {
        $table = (new self)->getTable();

        return static::query()
            ->select($table.'.*')
            // Table name is code-controlled (model + migration), never user input.
            ->from(new Expression(
                "(select distinct on (task_key) * from {$table} order by task_key, started_at desc) as {$table}" // @phpstan-ignore argument.type
            ))
            ->orderByDesc('started_at')
            ->get()
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
