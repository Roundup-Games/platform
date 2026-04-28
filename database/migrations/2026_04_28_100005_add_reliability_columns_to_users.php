<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('reliability_score')->nullable()->after('notification_settings');
            $table->timestamp('reliability_computed_at')->nullable()->after('reliability_score');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['reliability_score', 'reliability_computed_at']);
        });
    }
};
