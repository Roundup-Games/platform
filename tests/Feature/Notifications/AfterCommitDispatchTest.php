<?php

// ══════════════════════════════════════════════════════
// after_commit regression guard (config/queue.php)
//
// Five notification dispatch sites sit inside a DB::transaction
// (WaitlistPromoted via promoteNext/promoteNextFromEntityId, PlayerBenched,
// WaitlistPlaced, EntityCancelled x2 via UserAnonymizationService). With
// after_commit=false a rolled-back transaction would leak a queued
// notification describing state that never persisted. after_commit=true
// defers the ShouldQueue notification until the governing transaction
// commits, so a rollback discards it.
//
// This is a documented Laravel framework guarantee for the async drivers
// (redis/database/beanstalkd/sqs) used in production. The guarantee cannot
// be exercised under the test harness: the `sync` driver runs jobs inline
// (ignoring after_commit by design) and the DatabaseTransactions wrapper
// holds an outer transaction open for the whole test. So we guard the
// production config values directly — if any connection regresses to false,
// this test fails with the rollback-leak rationale.
// ══════════════════════════════════════════════════════

it('locks in after_commit=true on every async queue connection', function () {
    foreach (['database', 'beanstalkd', 'sqs', 'redis'] as $connection) {
        expect(config("queue.connections.{$connection}.after_commit"))
            ->toBeTrue(
                "queue.connections.{$connection}.after_commit must stay true — disabling it lets a "
                .'rolled-back transaction leak a queued notification about state that never persisted. '
                .'See WaitlistService::promoteNext/promoteNextFromEntityId, OverflowRouter::placeAcceptedInvitee, '
                .'and UserAnonymizationService::cancelSoleHostedEntities for the in-transaction dispatch sites.'
            );
    }
})->group('smoke');
