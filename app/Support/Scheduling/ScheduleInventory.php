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
    /** Container marker: this helper has required console.php itself. */
    private const string CONSOLE_LOADED = 'schedule.inventory.console-loaded';

    /**
     * All registered schedule events, loading routes/console.php first
     * when this process has not kernel-loaded it (web context).
     *
     * @return list<Event>
     */
    public static function events(): array
    {
        $schedule = app(Schedule::class);

        // Require AT MOST ONCE per process: emptiness is the web-context
        // signal (console processes arrive kernel-loaded and non-empty),
        // and the marker stops a legitimately empty console.php from
        // being re-required — re-registering its Artisan closures — on
        // every call.
        if ($schedule->events() === [] && ! app()->bound(self::CONSOLE_LOADED)) {
            // registrations in routes/console.php go through the Schedule
            // FACADE — pin container and facade to the same instance so the
            // definitions land on the schedule this helper returns.
            app()->instance(Schedule::class, $schedule);
            Facades\Schedule::swap($schedule);

            require base_path('routes/console.php');

            // Container-scoped on purpose: it resets with the app (a
            // static would outlive per-test/per-request containers and
            // skip the require for the rest of the process).
            app()->instance(self::CONSOLE_LOADED, true);
        }

        return array_values($schedule->events());
    }
}
