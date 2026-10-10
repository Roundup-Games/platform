<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convert the last two app-owned bigint id tables to uuid (D170).
 *
 * gm_social_links and suppressed_invite_emails have no inbound FKs (the
 * public surface of a short link is its code; social links and suppression
 * entries are addressed by their owning user/email_hash), so the conversion
 * is a drop-PK / retype / re-add-PK with fresh uuids for existing rows.
 * Existing-row ids get gen_random_uuid() (v4) — one-time values, ordered
 * generation (v7) applies to new rows via HasPlatformUuid.
 *
 * short_links + short_link_hits deliberately stay bigint: hottest insert
 * tables, pure internal counters, pinned as exemptions in
 * scripts/check_platform_invariants.sh.
 */
return new class extends Migration
{
    private const TABLES = [
        'gm_social_links',
        'suppressed_invite_emails',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $key = "{$table}_pkey";

            // Drop the auto-increment default first: PG refuses to cast a
            // column whose default (nextval) cannot follow the new type.
            // Ids are generated app-side (HasPlatformUuid) after this point.
            DB::statement("ALTER TABLE {$table} ALTER COLUMN id DROP DEFAULT");
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$key}");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN id TYPE uuid USING gen_random_uuid()");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$key} PRIMARY KEY (id)");
            DB::statement("DROP SEQUENCE IF EXISTS {$table}_id_seq");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $key = "{$table}_pkey";

            DB::statement('CREATE TEMP SEQUENCE legacy_id_seq');

            try {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$key}");
                DB::statement("ALTER TABLE {$table} ALTER COLUMN id TYPE bigint USING nextval('legacy_id_seq')");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$key} PRIMARY KEY (id)");
            } finally {
                DB::statement('DROP SEQUENCE IF EXISTS legacy_id_seq');
            }
        }
    }
};
