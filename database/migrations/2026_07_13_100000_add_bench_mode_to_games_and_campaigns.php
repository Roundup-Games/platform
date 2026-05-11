<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->boolean('bench_mode')->default(false)->after('max_players');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->boolean('bench_mode')->default(true)->after('max_players');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('bench_mode');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('bench_mode');
        });
    }
};
