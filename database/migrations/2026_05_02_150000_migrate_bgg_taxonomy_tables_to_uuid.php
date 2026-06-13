<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Migrate 5 BGG taxonomy tables from auto-increment integer PKs to UUID v7.
 *
 * Tables migrated:
 *   - game_system_categories
 *   - game_system_mechanics
 *   - game_system_designers
 *   - game_system_publishers
 *   - game_system_families
 *
 * FK columns updated (pivot tables, cross-link tables):
 *   - game_system_category.game_system_category_id  → uuid
 *   - game_system_mechanic.game_system_mechanic_id  → uuid
 *   - game_system_designer.game_system_designer_id  → uuid
 *   - game_system_publisher.game_system_publisher_id → uuid
 *   - game_system_family.game_system_family_id      → uuid
 *   - game_system_category_relations.category_id     → uuid
 *   - game_system_category_relations.related_category_id → uuid
 *   - game_system_mechanic_relations.mechanic_id     → uuid
 *   - game_system_mechanic_relations.related_mechanic_id → uuid
 *
 * Strategy per table:
 *   1. Add temporary uuid column.
 *   2. Backfill with UUID v7 values.
 *   3. Build old-id → new-uuid map.
 *   4. Drop FK constraints on pivot / cross-link tables referencing the old PK.
 *   5. Drop old PK, rename uuid to id as primary.
 *   6. Drop and re-add FK columns in pivot / cross-link tables as uuid with restored constraints.
 */
return new class extends Migration
{
    private array $tables = [
        'game_system_categories',
        'game_system_mechanics',
        'game_system_designers',
        'game_system_publishers',
        'game_system_families',
    ];

    /**
     * Map from taxonomy table name to [pivot table, FK column name].
     */
    private array $pivotMap = [
        'game_system_categories' => ['game_system_category',  'game_system_category_id'],
        'game_system_mechanics' => ['game_system_mechanic',   'game_system_mechanic_id'],
        'game_system_designers' => ['game_system_designer',   'game_system_designer_id'],
        'game_system_publishers' => ['game_system_publisher',  'game_system_publisher_id'],
        'game_system_families' => ['game_system_family',     'game_system_family_id'],
    ];

    /**
     * Map from taxonomy table name to [cross-link table, FK columns[]].
     */
    private array $crossLinkMap = [
        'game_system_categories' => [
            'game_system_category_relations', ['category_id', 'related_category_id'],
        ],
        'game_system_mechanics' => [
            'game_system_mechanic_relations', ['mechanic_id', 'related_mechanic_id'],
        ],
    ];

    public function up(): void
    {
        $idMaps = [];

        // Phase 1: Add uuid column and backfill for each taxonomy table
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->uuid('uuid')->nullable()->after('id');
            });

            $rows = DB::table($table)->get();
            foreach ($rows as $row) {
                DB::table($table)
                    ->where('id', $row->id)
                    ->update(['uuid' => (string) Str::orderedUuid()]);
            }

            $idMaps[$table] = DB::table($table)->pluck('uuid', 'id');
        }

        // Phase 2: Drop FK constraints on pivot tables and cross-link tables
        foreach ($this->pivotMap as $taxonomyTable => [$pivotTable, $fkColumn]) {
            Schema::table($pivotTable, function (Blueprint $t) use ($fkColumn) {
                $t->dropForeign([$fkColumn]);
            });
        }

        foreach ($this->crossLinkMap as $taxonomyTable => [$crossLinkTable, $fkColumns]) {
            Schema::table($crossLinkTable, function (Blueprint $t) use ($fkColumns) {
                foreach ($fkColumns as $col) {
                    $t->dropForeign([$col]);
                }
            });
        }

        // Phase 3: Map old FK values to new UUIDs in pivot and cross-link tables
        foreach ($this->pivotMap as $taxonomyTable => [$pivotTable, $fkColumn]) {
            $map = $idMaps[$taxonomyTable];
            foreach ($map as $oldId => $newUuid) {
                DB::table($pivotTable)
                    ->where($fkColumn, $oldId)
                    ->update([$fkColumn => $newUuid]);
            }
        }

        foreach ($this->crossLinkMap as $taxonomyTable => [$crossLinkTable, $fkColumns]) {
            $map = $idMaps[$taxonomyTable];
            foreach ($map as $oldId => $newUuid) {
                foreach ($fkColumns as $col) {
                    DB::table($crossLinkTable)
                        ->where($col, $oldId)
                        ->update([$col => $newUuid]);
                }
            }
        }

        // Phase 4: Swap PK for each taxonomy table
        foreach ($this->tables as $table) {
            $map = $idMaps[$table];

            Schema::table($table, function (Blueprint $t) {
                $t->dropPrimary('id');
                $t->dropColumn('id');
            });

            Schema::table($table, function (Blueprint $t) {
                $t->uuid('id')->primary()->first();
            });

            DB::statement("UPDATE {$table} SET id = uuid");

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('uuid');
            });
        }

        // Phase 5: Drop and re-add FK columns as uuid in pivot tables
        foreach ($this->pivotMap as $taxonomyTable => [$pivotTable, $fkColumn]) {
            // Drop primary key (composite) if this column is part of it
            Schema::table($pivotTable, function (Blueprint $t) {
                $t->dropPrimary();
            });

            Schema::table($pivotTable, function (Blueprint $t) use ($fkColumn) {
                $t->dropColumn($fkColumn);
            });

            Schema::table($pivotTable, function (Blueprint $t) use ($fkColumn, $taxonomyTable) {
                $t->uuid($fkColumn)->first();
                $t->foreign($fkColumn)->references('id')->on($taxonomyTable)->cascadeOnDelete();
            });

            // Re-establish composite primary key
            $gameSystemFk = 'game_system_id';
            Schema::table($pivotTable, function (Blueprint $t) use ($gameSystemFk, $fkColumn) {
                $t->primary([$gameSystemFk, $fkColumn]);
            });
        }

        // Phase 6: Drop and re-add FK columns as uuid in cross-link tables
        foreach ($this->crossLinkMap as $taxonomyTable => [$crossLinkTable, $fkColumns]) {
            Schema::table($crossLinkTable, function (Blueprint $t) use ($fkColumns) {
                foreach ($fkColumns as $col) {
                    $t->dropColumn($col);
                }
            });

            Schema::table($crossLinkTable, function (Blueprint $t) use ($fkColumns, $taxonomyTable) {
                foreach ($fkColumns as $col) {
                    $t->uuid($col)->first();
                    $t->foreign($col)->references('id')->on($taxonomyTable)->cascadeOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        // Revert cross-link tables
        foreach ($this->crossLinkMap as $taxonomyTable => [$crossLinkTable, $fkColumns]) {
            Schema::table($crossLinkTable, function (Blueprint $t) use ($fkColumns) {
                foreach ($fkColumns as $col) {
                    $t->dropForeign([$col]);
                }
                foreach ($fkColumns as $col) {
                    $t->dropColumn($col);
                }
            });

            Schema::table($crossLinkTable, function (Blueprint $t) use ($fkColumns, $taxonomyTable) {
                foreach ($fkColumns as $col) {
                    $t->foreignId($col)->first()->constrained($taxonomyTable)->cascadeOnDelete();
                }
            });
        }

        // Revert pivot tables
        foreach ($this->pivotMap as $taxonomyTable => [$pivotTable, $fkColumn]) {
            Schema::table($pivotTable, function (Blueprint $t) {
                $t->dropPrimary();
            });

            Schema::table($pivotTable, function (Blueprint $t) use ($fkColumn) {
                $t->dropForeign([$fkColumn]);
                $t->dropColumn($fkColumn);
            });

            Schema::table($pivotTable, function (Blueprint $t) use ($fkColumn, $taxonomyTable) {
                $t->foreignId($fkColumn)->first()->constrained($taxonomyTable)->cascadeOnDelete();
                $t->primary(['game_system_id', $fkColumn]);
            });
        }

        // Revert taxonomy PKs to auto-increment
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropPrimary('id');
                $t->dropColumn('id');
            });

            Schema::table($table, function (Blueprint $t) {
                $t->id()->first();
            });
        }
    }
};
