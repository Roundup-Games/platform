<?php

use App\Support\Queue\JobCatalog;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

//
// JobCatalog: the expected job fleet, reflected from app/Jobs. The catalog
// must contain every job class on disk and attribute queues correctly —
// it is what the admin Queue Structure page renders.
beforeEach(function () {
    Cache::flush();
});

it('includes every job class in app/Jobs', function () {
    // Recursive scan, mirroring the catalog's discovery: a job in a
    // subdirectory must appear in BOTH or this pin is vacuous.
    $onDisk = collect(Finder::create()
        ->files()
        ->in(app_path('Jobs'))
        ->name('*.php'))
        ->map(fn (SplFileInfo $file): string => str_replace(DIRECTORY_SEPARATOR, '\\', str_replace('.php', '', $file->getRelativePathname())))
        ->values()
        ->all();

    $catalog = collect(JobCatalog::jobs())->pluck('short')->all();

    expect($catalog)->toHaveCount(count($onDisk))
        ->and(array_diff($onDisk, $catalog))->toBeEmpty()
        ->and(array_diff($catalog, $onDisk))->toBeEmpty();
});

it('attributes explicit queues and marks inferred defaults', function () {
    $jobs = collect(JobCatalog::jobs())->keyBy('short');

    expect($jobs->get('WarmDashboardCache')['queue'])->toBe('discovery')
        ->and($jobs->get('WarmDashboardCache')['queue_inferred'])->toBeFalse()
        ->and($jobs->get('UpdateUserDiscoveryCache')['queue'])->toBe('discovery')
        ->and($jobs->get('SyncGameSystemsFromBgg')['queue'])->toBe('default')
        ->and($jobs->get('SyncGameSystemsFromBgg')['queue_inferred'])->toBeFalse()
        // No onQueue call in the constructor → connection default, honestly
        // labeled as inferred so the admin never mistakes it for a choice.
        ->and($jobs->get('AttendanceNudgeJob')['queue'])->toBe('default')
        ->and($jobs->get('AttendanceNudgeJob')['queue_inferred'])->toBeTrue();
});

it('reflects execution properties and dispatch source', function () {
    $jobs = collect(JobCatalog::jobs())->keyBy('short');

    expect($jobs->get('ComputePlatformScores')['tries'])->toBe(1)
        ->and($jobs->get('ComputePlatformScores')['timeout'])->toBe(300)
        // Referenced by routes/console.php (platform-scores:compute command)
        ->and($jobs->get('ComputePlatformScores')['scheduled'])->toBeTrue()
        // Dispatched from listeners/services, not the schedule file
        ->and($jobs->get('AttendanceNudgeJob')['scheduled'])->toBeFalse();
});
