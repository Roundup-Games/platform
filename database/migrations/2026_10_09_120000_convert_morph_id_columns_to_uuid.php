<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert every varchar-typed morph *_id column we own to native uuid (D170).
 *
 * These columns were created as varchar(36)/varchar(255) because morph
 * targets historically had mixed key types. Every morph target has been
 * uuid-keyed since the M032 UUID migration, so the string seam is pure
 * legacy — kept alive only by the unowned IMPLICIT varchar→uuid cast in
 * the schema (dropped by the companion migration). After this migration
 * there is exactly one id type across every owned table.
 *
 * ALTER COLUMN TYPE ... USING rebuilds dependent indexes/constraints.
 * A pre-flight query fails loudly (with counts + samples) if any stored
 * value is not a valid uuid, so deploy blocks on dirty data instead of
 * silently corrupting it.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string}> table + morph base name pairs */
    private const MORPH_COLUMNS = [
        ['activity_logs', 'subject'],
        ['customers', 'billable'],
        ['media', 'model'],
        ['model_has_permissions', 'model'],
        ['model_has_roles', 'model'],
        ['notifications', 'notifiable'],
        ['reviews', 'reviewable'],
        ['seo', 'model'],
        ['short_links', 'linkable'],
        ['subscriptions', 'billable'],
        ['transactions', 'billable'],
    ];

    public function up(): void
    {
        foreach (self::MORPH_COLUMNS as [$table, $base]) {
            $column = "{$base}_id";

            $invalid = DB::table($table)
                ->whereNotNull($column)
                ->whereRaw("{$column} !~* ? ", ['^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'])
                ->count();

            if ($invalid > 0) {
                $samples = DB::table($table)
                    ->whereNotNull($column)
                    ->whereRaw("{$column} !~* ? ", ['^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'])
                    ->pluck($column)
                    ->take(5)
                    ->implode(', ');

                throw new RuntimeException(
                    "Cannot convert {$table}.{$column} to uuid: {$invalid} non-uuid value(s). Samples: {$samples}"
                );
            }

            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE uuid USING {$column}::uuid");
        }
    }

    public function down(): void
    {
        foreach (self::MORPH_COLUMNS as [$table, $base]) {
            $column = "{$base}_id";
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE varchar(36) USING {$column}::text");
        }
    }
};
