<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_participants', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable()->after('status');
        });

        // Backfill existing rows — they should NOT trigger trypass.
        // Setting to a date far in the past ensures they won't match `>= now()-5min`.
        DB::table('game_participants')->whereNull('created_at')->update([
            'created_at' => now()->subYear(),
        ]);
    }

    public function down(): void
    {
        Schema::table('game_participants', function (Blueprint $table) {
            $table->dropColumn('created_at');
        });
    }
};
