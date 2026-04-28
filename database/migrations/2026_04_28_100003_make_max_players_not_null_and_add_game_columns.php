<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Migrate existing nulls before adding NOT NULL constraint
        DB::statement('UPDATE games SET max_players = 6 WHERE max_players IS NULL');
        DB::statement('UPDATE games SET min_players = 2 WHERE min_players IS NULL');

        Schema::table('games', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_players')->nullable(false)->default(6)->change();
            $table->unsignedSmallInteger('min_players')->nullable(false)->default(2)->change();
            $table->text('recap')->nullable()->after('reminder_sent_at');
            $table->decimal('min_reliability_preference', 5, 2)->nullable()->after('recap');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['recap', 'min_reliability_preference']);
            $table->unsignedSmallInteger('max_players')->nullable()->default(null)->change();
            $table->unsignedSmallInteger('min_players')->nullable()->default(null)->change();
        });
    }
};
