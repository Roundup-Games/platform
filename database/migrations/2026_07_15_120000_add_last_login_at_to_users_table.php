<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add last_login_at to users for authoritative session-boundary tracking.
     *
     * Has multiple consumers beyond analytics: dormant-account detection,
     * admin "last seen" reporting, and inactive-user cleanup. The column is
     * stamped on every authentication (see RecordUserSignIn listener) via
     * saveQuietly, so it does not trigger model observers.
     *
     * Idempotent guard: the squashed schema baseline (pgsql-schema.sql) already
     * declares this column for fresh installs. This migration covers existing
     * environments that load the dump once and then apply batch-2+ migrations.
     * The hasColumn check prevents a duplicate-column error on databases where
     * the baseline already created it.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('last_login_at')->nullable()->after('updated_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('last_login_at');
            });
        }
    }
};
