<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('game_systems', function (Blueprint $table) {
            $table->unsignedInteger('platform_score')->default(0)->index()->after('bgg_rank');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_systems', function (Blueprint $table) {
            $table->dropIndex(['platform_score']);
            $table->dropColumn('platform_score');
        });
    }
};
