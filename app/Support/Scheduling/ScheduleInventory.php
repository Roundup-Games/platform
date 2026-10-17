<?php

namespace App\Support\Scheduling;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades;

/**
 * Schedule inventory that works in web context (M068).
 *
 * routes/console.php — where every schedule entry is registered — is only
 * loaded after the Console kernel resolves
 * (Foundation\Configuration\ApplicationBuilder::withCommands). That happens
 * in artisan/horizon/schedule:work processes, never in FPM web workers: the
 * admin Scheduled Tasks page and ScheduleHealth::summary() saw an EMPTY
 * schedule in production while tests (which run in console) saw the full
 * one. This helper loads the definitions on demand whenever the container's
 * schedule has none — a console process that already kernel-loaded them
 * keeps its registrations untouched.
 */
final class ScheduleInventory
{
    /**
     * All registered schedule events, loading routes/console.php first
     * when the container's schedule is still empty (web context).
     *
     * @return list<Event>
     */
    public static function events(): array
    {
        $schedule = app(Schedule::class);

        if ($schedule->events() === []) {
            // registrations in routes/console.php go through the Schedule
            // FACADE — pin container and facade to the same instance so the
            // definitions land on the schedule this helper returns.
            app()->instance(Schedule::class, $schedule);
            Facades\Schedule::swap($schedule);

            require base_path('routes/console.php');
        }

        return array_values($schedule->events());
    }
}
