<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->text('location_instructions')->nullable()->after('location_id');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->text('location_instructions')->nullable()->after('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('location_instructions');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('location_instructions');
        });
    }
};
