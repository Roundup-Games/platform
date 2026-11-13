<?php

// Scaling-prep pins (2026-11-13 research + live measurement): the app slots
// shipped with the serversideup image's default FPM pool (ondemand,
// max_children=20), which pays a 50-200ms worker-spawn on the request path
// whenever traffic ramps from idle — the first p99 cliff under load. Both
// slots now run a measured static pool (32 children ≈ 2.75GB at the measured
// 82-90MB worker RSS, within 16 vCPU x 2), and opcache tracks enough scripts
// for the 26.7k-file tree with headroom. These tests keep the slots in
// lockstep and the sizes honest as compose/Dockerfile evolve.

/**
 * Extract one top-level compose service block by slicing between the
 * matched service-name offsets — no pairing assumptions about preamble
 * pieces.
 */
function app_service_block(string $service): ?string
{
    $compose = (string) file_get_contents(base_path('docker-compose.yml'));

    if (preg_match_all('/^  (?P<name>[a-z0-9-]+):/m', $compose, $m, PREG_OFFSET_CAPTURE) === 0) {
        return null;
    }

    foreach ($m['name'] as $i => [$name, $offset]) {
        if ($name !== $service) {
            continue;
        }

        $start = $offset + strlen($name) + 2;
        $end = $m['name'][$i + 1][1] ?? strlen($compose);

        return substr($compose, $start, $end - $start);
    }

    return null;
}

it('runs both app slots on the same static, sized FPM pool', function () {
    foreach (['app', 'app-green'] as $service) {
        $block = app_service_block($service);

        expect($block)->not->toBeNull("compose service {$service} must exist")
            ->and($block)->toContain('PHP_FPM_PM_CONTROL: static')
            ->and($block)->toContain('PHP_FPM_PM_MAX_CHILDREN: "32"')
            ->and($block)->toContain('PHP_FPM_PM_MAX_REQUESTS: "1000"');
    }
});

it('does not declare an FPM pool for the worker', function () {
    // The worker stage strips nginx + php-fpm entirely — pool envs there
    // would be silently dead config.
    expect(app_service_block('worker'))->not->toContain('PHP_FPM_PM_');
});

it('gives opcache headroom above the PHP file count', function () {
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    expect($dockerfile)->toContain('opcache.max_accelerated_files=50000');

    // The pin must stay honest against the real tree: if the file count
    // ever grows past ~60% of the cap, this fails and the cap moves up.
    $fileCount = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        base_path('app'),
        FilesystemIterator::SKIP_DOTS,
    ));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $fileCount++;
        }
    }

    $vendor = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        base_path('vendor'),
        FilesystemIterator::SKIP_DOTS,
    ));
    foreach ($vendor as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $fileCount++;
        }
    }

    expect($fileCount)->toBeLessThan(50000 * 0.6);
});
