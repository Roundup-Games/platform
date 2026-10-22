<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Structural invariant for local_subscriptions (R083): at most ONE
 * active row per (user, plan, source).
 *
 * Every writer already assumes it — CompMembershipService::grant finds
 * the active comp row to re-grant, GmRoleService::
 * activateGmSubscription updateOrCreates the self-serve row — but the
 * lookup-then-write pattern races itself: two admins granting
 * concurrently, or a user double-clicking self-serve activation, both
 * miss the first() and insert. Benefits and reporting are idempotent,
 * so duplicates were only ever muddy history — but the invariant
 * deserved to be structural, not conventional.
 *
 * Partial (WHERE status = 'active'): expired/canceled history stays
 * unlimited, and comp + self-serve CAN coexist on the same plan
 * (different source) — that is the "second active membership remains"
 * semantics the expiry sweep tests pin. Raw DDL because the Blueprint
 * ->where() modifier does not attach to unique() on this framework
 * version — the shape must be exact, so it is spelled out.
 *
 * The badge tables precedent (2026_10_17_110000): writers converge on
 * the unique violation instead of treating it as an error.
 *
 * The race above means duplicate active pairs can ALREADY exist when
 * this migration runs, and create unique index aborts on the first
 * one — mid-deploy, with every later migration unapplied. So the
 * index is preceded by a one-time dedupe: per (user, plan, source),
 * the newest active row (starts_at, then created_at) survives and
 * older duplicates become canceled history. The sweep direction only
 * (cancel); the down() index drop cannot resurrect them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('local_subscriptions', 'local_subscriptions_one_active_per_source_unique')) {
            // Newest active row per key wins; the losers flip to canceled
            // history (the same terminal state every writer uses).
            DB::statement(<<<'SQL'
                update local_subscriptions as ls
                set status = 'canceled',
                    canceled_at = now()
                from (
                    select id,
                           row_number() over (
                               partition by user_id, membership_type_id, source
                               order by starts_at desc nulls last, created_at desc nulls last
                           ) as rn
                    from local_subscriptions
                    where status = 'active'
                ) as ranked
                where ranked.id = ls.id
                  and ranked.rn > 1
            SQL);

            DB::statement(
                'create unique index local_subscriptions_one_active_per_source_unique '.
                'on local_subscriptions (user_id, membership_type_id, source) '.
                "where status = 'active'"
            );
        }
    }

    public function down(): void
    {
        DB::statement('drop index if exists local_subscriptions_one_active_per_source_unique');
    }
};
