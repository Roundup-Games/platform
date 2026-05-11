<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_participants', function (Blueprint $table) {
            if (! Schema::hasColumn('campaign_participants', 'created_at')) {
                $table->timestamp('created_at')->nullable()->after('id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('campaign_participants', function (Blueprint $table) {
            $table->dropColumn('created_at');
        });
    }
};
