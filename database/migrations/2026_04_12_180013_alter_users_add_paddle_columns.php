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
