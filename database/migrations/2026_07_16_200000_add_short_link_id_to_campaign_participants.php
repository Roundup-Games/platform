<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('short_link_id')->nullable()->after('join_source');
            $table->foreign('short_link_id')->references('id')->on('short_links')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_participants', function (Blueprint $table) {
            $table->dropForeign(['short_link_id']);
            $table->dropColumn('short_link_id');
        });
    }
};
