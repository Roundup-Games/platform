<?php

// 2026-11-13 incident pin: every deploy's worker roll paid the full 5-minute
// stop_grace_period and ended in a SIGKILL, because the worker image inherited
// the serversideup base's STOPSIGNAL SIGQUIT (correct for nginx, which the
// worker stage strips) while s6-overlay deliberately ignores SIGQUIT — the
// stop signal landed on a no-op handler. These tests pin the contract that
// keeps `docker stop` / compose recreates graceful: the worker image stops on
// SIGTERM (s6 forwards it; Horizon drains its current job and exits), and the
// compose grace stays a CEILING for a worst-case 300s job, not an always-paid
// floor. See Dockerfile worker stage and docker-compose.yml worker service.

it('stops the worker image on SIGTERM', function () {
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    // The only STOPSIGNAL directive belongs to the worker stage.
    expect(preg_match_all('/^STOPSIGNAL\s/m', $dockerfile))->toBe(1);

    $workerStage = strstr($dockerfile, 'FROM app AS worker') ?: '';
    expect($workerStage)->not->toBe('')
        ->and($workerStage)->toContain('STOPSIGNAL SIGTERM');
});

it('does not override the nginx app slot stop signal', function () {
    // The app slots keep the base image's SIGQUIT — that is nginx's graceful
    // quit. A STOPSIGNAL declared before the worker stage would change how
    // the app containers retire; the worker-only declaration is deliberate.
    $beforeWorkerStage = strstr((string) file_get_contents(base_path('Dockerfile')), 'FROM app AS worker', true) ?: '';

    expect($beforeWorkerStage)->not->toContain('STOPSIGNAL');
});

it('keeps the worker stop grace at the 300s job-timeout ceiling', function () {
    $compose = (string) file_get_contents(base_path('docker-compose.yml'));

    $workerBlock = preg_split('/^  \w+:/m', $compose, -1, PREG_SPLIT_NO_EMPTY);
    $workerSection = collect($workerBlock)
        ->first(fn (string $block) => str_contains($block, 'platform-worker'));

    expect($workerSection)->not->toBeNull()
        ->and($workerSection)->toContain('stop_grace_period: 5m');
});
