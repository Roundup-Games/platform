<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop the unowned IMPLICIT varchar→uuid cast (D170).
 *
 * The cast was added manually (it exists only in the squashed schema dump,
 * owned by no migration) to paper over string-typed morph columns being
 * compared against native uuid keys. With every morph column now native
 * uuid, the cast has no reason to exist — and implicit casts are a global
 * semantic footgun (they silently change operator resolution for every
 * varchar/uuid comparison in the database).
 *
 * Requires the role running migrations to own the cast (it created it).
 * Fails loudly if it cannot.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP CAST IF EXISTS (character varying AS uuid)');
    }

    public function down(): void
    {
        // Intentionally not restored: the cast was an unowned workaround.
        // Restoring it would reintroduce silent cross-type comparisons.
    }
};
