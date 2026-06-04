<?php

use App\Services\BggSyncService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\InputOption;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('bgg:sync', function () {
    $ids = collect(explode(',', $this->option('ids') ?? ''))
        ->map(fn ($id) => trim($id))
        ->filter()
        ->map(fn ($id) => (int) $id)
        ->unique()
        ->values()
        ->toArray();

    if (empty($ids)) {
        $this->error('No BGG IDs provided. Use --ids=174430,25613');

        return 1;
    }

    $this->info('Syncing '.count($ids).' game(s) from BGG...');

    $service = app(BggSyncService::class);
    $result = $service->syncGameSystems($ids);

    $this->info("Synced: {$result['synced']}, Failed: {$result['failed']}");

    if (! empty($result['errors'])) {
        foreach ($result['errors'] as $error) {
            $this->warn("- {$error}");
        }

        return 1;
    }

    return 0;
})->purpose('Sync game systems from BoardGameGeek by BGG ID')
  ->addOption('ids', null, InputOption::VALUE_REQUIRED, 'Comma-delimited BGG IDs');

Artisan::command('bgg:seed-top500', function () {
    $this->info('Seeding top 500 BGG games...');

    $seedService = app(\App\Services\BggSeedService::class);

    $result = $seedService->seedTop500(function (string $message) {
        $this->line("  {$message}");
    });

    $this->newLine();
    $this->info('Seed complete:');
    $this->line("  Base games: {$result['base_synced']} synced, {$result['base_failed']} failed");
    $this->line("  Expansions discovered: {$result['total_expansions_discovered']}");
    $this->line("  Expansions synced: {$result['expansions_synced']}, failed: {$result['expansions_failed']}");
    $this->line('  Total: ' . ($result['base_synced'] + $result['expansions_synced']) . ' game systems');

    return ($result['base_failed'] + $result['expansions_failed']) > 0 ? 1 : 0;
})->purpose('Seed database with top 500 BGG games and their expansions');

Artisan::command('bgg:weekly-sync', function () {
    $ids = \App\Models\GameSystem::whereNotNull('bgg_id')->pluck('bgg_id');

    if ($ids->isEmpty()) {
        $this->info('No game systems to sync.');

        return 0;
    }

    $this->info('Weekly sync: syncing ' . count($ids) . ' game system(s) from BGG...');

    try {
        $service = app(BggSyncService::class);
        $result = $service->syncGameSystems($ids->toArray());

        $this->info("Synced: {$result['synced']}, Failed: {$result['failed']}");

        if (! empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->warn("- {$error}");
            }
        }

        return $result['failed'] > 0 ? 1 : 0;
    } catch (\Throwable $e) {
        $this->error("Weekly sync failed: {$e->getMessage()}");

        return 1;
    }
})->purpose('Run weekly BGG sync for all existing game systems');

Artisan::command('platform-scores:compute', function () {
    $service = app(\App\Services\PlatformScoreService::class);
    $stats = $service->computeAll();

    $this->info("Scored {$stats['scored']} systems ({$stats['errors']} errors) in {$stats['duration_ms']}ms");

    return $stats['errors'] > 0 ? 1 : 0;
})->purpose('Compute platform popularity scores for all game systems');

use Illuminate\Support\Facades\Schedule;

Schedule::command('bgg:weekly-sync')->weekly()->mondays()->at('03:00');

Schedule::command('platform-scores:compute')->dailyAt('03:00')->onOneServer();

Schedule::command('discovery:sweep-active --window=60')
    ->everyTenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('pwa:send-session-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('waitlist:sweep-expired-confirmations')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('attendance:resolve')
    ->everyThirtyMinutes()
    ->onOneServer();

Schedule::command('attendance:auto-complete')
    ->everyThirtyMinutes()
    ->onOneServer();

Schedule::command('attendance:nudge')
    ->everyThirtyMinutes()
    ->onOneServer();

// Prune stale PWA data
Schedule::command('pwa:prune-stale-subscriptions')->weekly()->sundays()->at('03:00')->onOneServer();
Schedule::command('pwa:prune-visits')->monthly()->onOneServer();

// Escalated helpdesk — auto-escalation, SLA enforcement, ticket lifecycle
Schedule::command('escalated:evaluate-escalations')->everyFiveMinutes()->onOneServer();
Schedule::command('escalated:run-automations')->everyMinute()->onOneServer();
Schedule::command('escalated:wake-snoozed-tickets')->everyFiveMinutes()->onOneServer();
Schedule::command('escalated:close-resolved')->dailyAt('02:00')->onOneServer();
Schedule::command('escalated:purge-activities')->dailyAt('03:30')->onOneServer();

// Short link maintenance — entity-driven expiry + expired link cleanup + analytics retention
Schedule::command('short-links:prune --days=90')->dailyAt('03:00')->onOneServer();

// Privacy — anonymize invitee_email on participants whose entity ended 90+ days ago
Schedule::command('anonymize:stale-invite-emails')->weekly()->sundays()->at('04:00')->onOneServer();

// Privacy — prune expired data export ZIPs (older than 7 days)
Schedule::command('exports:prune --days=7')->dailyAt('04:30')->onOneServer();
