<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the game→game link behind the linked recreate (D318/D319).
 *
 * A host who cancels a game may declare "Recreate with changes". The
 * reliability offence is then recorded exactly as today (LateCancel, −1.2)
 * and is flipped to the neutral cancelled_early weight ONLY once the
 * replacement is actually published. That needs two nullable columns, one on
 * each side of the link:
 *
 *  - recreates_game_id lives on the NEW (replacement) game and points at the
 *    cancelled predecessor. nullOnDelete: losing the predecessor detaches the
 *    replacement, it never destroys it. The explicit ->index() is required —
 *    constrained() creates the constraint but not an index on the referencing
 *    column, and Postgres never auto-indexes FKs (K015). It is also the lookup
 *    path for "does this cancelled game already have a replacement?".
 *
 *    Deliberately NOT unique: one replacement per cancelled game is enforced
 *    by the create flow, not by the database. A second publish from the same
 *    ?recreate= link degrades to a plain, unlinked game rather than failing.
 *
 *  - recreate_declared_at lives on the OLD (cancelled) game and is the PENDING
 *    marker. Non-null means the host declared the recreate when cancelling —
 *    the opt-in is never inferred from a later clone (D319). No index: it is
 *    never filtered on alone; the offence flip reads it by the predecessor's
 *    primary key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->foreignUuid('recreates_game_id')->nullable()->index()->constrained('games')->nullOnDelete();
            $table->timestamp('recreate_declared_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropForeign(['recreates_game_id']);
            $table->dropColumn(['recreates_game_id', 'recreate_declared_at']);
        });
    }
};
