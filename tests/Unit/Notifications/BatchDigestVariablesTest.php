<?php

use App\Models\NotificationBatch;

/*
 * Digest-variable derivation (M082/S02/T01): the pure core every
 * batched channel renderer (T02) consumes — totals, the distinct actor
 * set in arrival order with its capped display prefix, and the bounded
 * activities preview. Pure array-in/array-out over the item payload
 * contract: no model, no rows (Unit/Notifications is a container-only
 * directory with a DB-write leak guard).
 */

/**
 * One buffered event snapshot — the item payload contract documented on
 * BatchedNotification and written by the buffer path (T04). Named
 * distinctly from batchFlushItemPayload() (one global function
 * namespace per --parallel worker) but the identical contract shape.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function digestFixturePayload(array $overrides = []): array
{
    return array_merge([
        'actor_id' => '01900000-0000-7000-8000-00000000000a',
        'actor_name' => 'Sarah',
        'entity_type' => 'game',
        'entity_id' => '01900000-0000-7000-8000-0000000000e1',
        'entity_name' => 'Tuesday Runde',
        'event' => 'participant_joined',
        'context' => [],
        'action_url' => 'https://roundup.test/en/games/01900000-0000-7000-8000-0000000000e1',
        'occurred_at' => '2026-11-19T11:58:00+00:00',
    ], $overrides);
}

describe('NotificationBatch::digestVariables', function () {
    it('totals every buffered payload as one activity', function () {
        $digest = NotificationBatch::digestVariables([
            digestFixturePayload(),
            digestFixturePayload(),
            digestFixturePayload(['actor_name' => 'Alex']),
        ]);

        expect($digest['total_activities'])->toBe(3);
    });

    it('counts distinct actors, not activities', function () {
        // Sarah, Alex, Sarah, Mara, Alex, Tim — six activities, four actors.
        $digest = NotificationBatch::digestVariables([
            digestFixturePayload(['actor_id' => 'a-sarah', 'actor_name' => 'Sarah']),
            digestFixturePayload(['actor_id' => 'a-alex', 'actor_name' => 'Alex']),
            digestFixturePayload(['actor_id' => 'a-sarah', 'actor_name' => 'Sarah']),
            digestFixturePayload(['actor_id' => 'a-mara', 'actor_name' => 'Mara']),
            digestFixturePayload(['actor_id' => 'a-alex', 'actor_name' => 'Alex']),
            digestFixturePayload(['actor_id' => 'a-tim', 'actor_name' => 'Tim']),
        ]);

        expect($digest['total_activities'])->toBe(6)
            ->and($digest['total_actors'])->toBe(4);
    });

    it('caps display actors at three, first arrival order', function () {
        // Five distinct actors arrive as Emma, Alex, Sarah, Mara, Tim —
        // the display prefix names the first three, in arrival order.
        $digest = NotificationBatch::digestVariables([
            digestFixturePayload(['actor_id' => 'a-emma', 'actor_name' => 'Emma']),
            digestFixturePayload(['actor_id' => 'a-alex', 'actor_name' => 'Alex']),
            digestFixturePayload(['actor_id' => 'a-sarah', 'actor_name' => 'Sarah']),
            digestFixturePayload(['actor_id' => 'a-mara', 'actor_name' => 'Mara']),
            digestFixturePayload(['actor_id' => 'a-tim', 'actor_name' => 'Tim']),
        ]);

        expect($digest['display_actors'])->toBe([
            ['id' => 'a-emma', 'name' => 'Emma'],
            ['id' => 'a-alex', 'name' => 'Alex'],
            ['id' => 'a-sarah', 'name' => 'Sarah'],
        ]);
    });

    it('returns every distinct actor below the display cap', function () {
        $digest = NotificationBatch::digestVariables([
            digestFixturePayload(['actor_id' => 'a-sarah', 'actor_name' => 'Sarah']),
            digestFixturePayload(['actor_id' => 'a-sarah', 'actor_name' => 'Sarah']),
            digestFixturePayload(['actor_id' => 'a-alex', 'actor_name' => 'Alex']),
        ]);

        expect($digest['display_actors'])->toBe([
            ['id' => 'a-sarah', 'name' => 'Sarah'],
            ['id' => 'a-alex', 'name' => 'Alex'],
        ]);
    });

    it('keeps the first-arrival name for a repeated actor id', function () {
        // A rename mid-burst (each snapshot bakes the buffer-time name)
        // must not fork one actor into two digest entries — the first
        // snapshot's name is the one every channel renders.
        $digest = NotificationBatch::digestVariables([
            digestFixturePayload(['actor_id' => 'a-sarah', 'actor_name' => 'Sarah']),
            digestFixturePayload(['actor_id' => 'a-sarah', 'actor_name' => 'Sarah K.']),
        ]);

        expect($digest['total_actors'])->toBe(1)
            ->and($digest['display_actors'])->toBe([['id' => 'a-sarah', 'name' => 'Sarah']]);
    });

    it('dedupes id-less snapshots by name', function () {
        // The payload contract allows an empty actor_id; identity falls
        // back to the name so the same id-less actor does not explode.
        $digest = NotificationBatch::digestVariables([
            digestFixturePayload(['actor_id' => '', 'actor_name' => 'Sarah']),
            digestFixturePayload(['actor_id' => '', 'actor_name' => 'Sarah']),
            digestFixturePayload(['actor_id' => 'a-alex', 'actor_name' => 'Alex']),
        ]);

        expect($digest['total_actors'])->toBe(2)
            ->and($digest['display_actors'])->toBe([
                ['id' => '', 'name' => 'Sarah'],
                ['id' => 'a-alex', 'name' => 'Alex'],
            ]);
    });

    it('previews at most ten payloads in arrival order', function () {
        $payloads = array_map(
            fn (int $i): array => digestFixturePayload([
                'actor_id' => "a-{$i}",
                'actor_name' => "Actor {$i}",
            ]),
            range(1, 12),
        );

        $digest = NotificationBatch::digestVariables($payloads);

        expect($digest['activities_preview'])->toBe(array_slice($payloads, 0, NotificationBatch::DIGEST_PREVIEW_ITEMS))
            ->and($digest['total_activities'])->toBe(12);
    });

    it('previews every payload under the preview cap', function () {
        $payloads = [
            digestFixturePayload(['actor_id' => 'a-1', 'actor_name' => 'Actor 1']),
            digestFixturePayload(['actor_id' => 'a-2', 'actor_name' => 'Actor 2']),
            digestFixturePayload(['actor_id' => 'a-3', 'actor_name' => 'Actor 3']),
        ];

        $digest = NotificationBatch::digestVariables($payloads);

        expect($digest['activities_preview'])->toBe($payloads);
    });

    it('derives an empty digest from an empty batch', function () {
        // The flush path guards against empty batches before rendering,
        // but the derivation is a pure function: [] in, zeroed digest out.
        expect(NotificationBatch::digestVariables([]))->toBe([
            'total_activities' => 0,
            'total_actors' => 0,
            'display_actors' => [],
            'activities_preview' => [],
        ]);
    });

    it('degrades defensively on payloads missing actor keys', function () {
        // A contract-breaking snapshot (the buffer path always writes
        // both keys) must not crash the digest — it counts as one
        // unknown actor so every channel still renders consistently.
        $digest = NotificationBatch::digestVariables([
            array_diff_key(digestFixturePayload(), ['actor_id' => '', 'actor_name' => '']),
            digestFixturePayload(['actor_id' => 'a-alex', 'actor_name' => 'Alex']),
        ]);

        expect($digest['total_activities'])->toBe(2)
            ->and($digest['total_actors'])->toBe(2)
            ->and($digest['display_actors'][0])->toBe(['id' => '', 'name' => '']);
    });
});
