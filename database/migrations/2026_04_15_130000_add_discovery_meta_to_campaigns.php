<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_players')->nullable()->after('safety_rules');
            $table->unsignedTinyInteger('max_players')->nullable()->after('min_players');
            $table->string('experience_level', 30)->nullable()->after('max_players');
            $table->decimal('complexity', 3, 2)->nullable()->after('experience_level');
            $table->json('vibe_flags')->nullable()->after('complexity');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['min_players', 'max_players', 'experience_level', 'complexity', 'vibe_flags']);
        });
    }
};
