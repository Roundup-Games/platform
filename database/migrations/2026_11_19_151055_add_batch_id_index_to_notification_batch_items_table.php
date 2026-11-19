<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index the buffer's hot lookup path (M082 post-milestone review).
 *
 * notification_batch_items.batch_id is queried twice per buffered event —
 * the append path counts items (items()->count() inside the append
 * transaction) and every flush loads them (items()->get()) — but the FK
 * alone provides no index: PostgreSQL does not auto-index referencing
 * columns, so both were sequential scans over a table that grows with
 * every buffered burst. A plain btree on batch_id turns both into index
 * lookups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_batch_items', function (Blueprint $table) {
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('notification_batch_items', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
        });
    }
};
