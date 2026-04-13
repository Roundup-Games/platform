<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('paddle_id')->nullable()->unique()->after('remember_token');
            // NOTE: trial_ends_at was originally string — fixed to timestamp by migration 2026_04_13_122240
            $table->string('trial_ends_at')->nullable()->after('paddle_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['paddle_id', 'trial_ends_at']);
        });
    }
};
