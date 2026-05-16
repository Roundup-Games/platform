<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('short_link_hits', function (Blueprint $table) {
            $table->string('referer_domain', 255)->nullable()->after('referer');

            $table->index('referer_domain');
        });
    }

    public function down(): void
    {
        Schema::table('short_link_hits', function (Blueprint $table) {
            $table->dropIndex(['referer_domain']);
            $table->dropColumn('referer_domain');
        });
    }
};
