<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fixes C4: trial_ends_at was declared as string but User model casts it as
// 'datetime' and Cashier expects a timestamp column.
// Original migration: 2026_04_12_180013_alter_users_add_paddle_columns.php

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('trial_ends_at')->nullable()->change();
        });
    }
};
