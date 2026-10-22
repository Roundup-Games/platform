<?php

namespace Tests\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Deterministically simulate the lost race between a guard read and an
 * INSERT (SQLSTATE 23505 through the real unique index).
 *
 * When the code under test first saves a model of the given class, a
 * conflicting row is inserted through the query builder FIRST — inside
 * the same test transaction, firing no model events — so the code's own
 * INSERT hits the real unique index and the real constraint-violation
 * exception surfaces through the code's real mapping (the catch under
 * test). The injection fires via the model's `creating` event: AFTER any
 * locked/guarded read, BEFORE the INSERT statement — the exact race
 * window the backstop exists for.
 *
 * Why not two real database connections: the suite isolates tests with
 * DatabaseTransactions on the default connection, so parent rows created
 * in a test are invisible to a second connection (the duplicate insert
 * would fail on the FOREIGN KEY, never reaching the unique index), and
 * anything committed on a second connection leaks into the shared
 * testcontainer. This injection is single-threaded, deterministic, and
 * rolls back with the test — zero pollution, zero flake.
 *
 * Parity notes, so future readers trust the shape:
 * - The services' catch bodies under test are pure (constant return or
 *   translated throw, no DB re-read), so the simulated loser path is
 *   behaviorally identical to a real concurrent-winner race.
 * - One intentional difference: the injected "winner" row lives inside
 *   the same savepoint as the loser's insert, so the rollback removes
 *   both — post-state assertions correctly expect ZERO rows.
 */
trait InjectsConcurrentDuplicates
{
    /**
     * Arm the duplicate injection for one model class: the FIRST save of
     * that class after arming gets a conflicting row inserted ahead of
     * it. Arm right before the call under test; disarm in a finally
     * (the handler is a no-op after firing once either way).
     *
     * @param  class-string<Model>  $modelClass
     * @param  callable(object): array<string, mixed>  $duplicate  Builds the conflicting row's full
     *                                                             payload from the model about to be inserted — explicit
     *                                                             columns and values, no attribute copying: the payload must
     *                                                             satisfy every NOT NULL column and CHECK constraint itself.
     * @return callable(): void The disarm callback.
     */
    protected function injectDuplicateOnCreate(string $modelClass, callable $duplicate): callable
    {
        $armed = true;

        $modelClass::creating(function (object $model) use ($modelClass, $duplicate, &$armed): void {
            if (! $armed) {
                return;
            }

            $armed = false;

            DB::table((new $modelClass)->getTable())->insert($duplicate($model));
        });

        return function () use (&$armed): void {
            $armed = false;
        };
    }
}
