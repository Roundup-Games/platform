<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// Fixes C4: trial_ends_at was declared as string but User model casts it as
// 'datetime' and Cashier expects a timestamp column.
// Original migration: 2026_04_12_180013_alter_users_add_paddle_columns.php

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // PostgreSQL can't auto-cast varchar → timestamp, needs USING clause
            DB::statement('ALTER TABLE users ALTER COLUMN trial_ends_at TYPE timestamp(0) USING trial_ends_at::timestamp(0)');
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('trial_ends_at')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users ALTER COLUMN trial_ends_at TYPE varchar(255) USING trial_ends_at::varchar(255)");
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->string('trial_ends_at')->nullable()->change();
            });
        }
    }
};
