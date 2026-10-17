<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled-task run outcomes (observability track 2026-10-17).
 *
 * One row per dispatch of a scheduled task, written by the framework
 * scheduling events (ScheduledTaskStarting/Finished/Failed/Skipped and
 * ScheduledBackgroundTaskFinished). This is the durable answer to
 * "did the scheduled work actually run, and did it succeed" — the
 * schedule itself only says when something is DUE.
 *
 * Retention: pruned after 30 days via Prunable + model:prune; zombie
 * "running" rows older than 24h (container died mid-run) are pruned too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Resolved command string (TaskIdentity::command), e.g.
            // "attendance:resolve" or a closure description. Joins the
            // schedule view to its run history.
            $table->string('task_key');
            $table->string('status');
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable();
            // Milliseconds; null for background finishes (framework does
            // not time them) and for skipped runs.
            $table->unsignedBigInteger('runtime_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['task_key', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
