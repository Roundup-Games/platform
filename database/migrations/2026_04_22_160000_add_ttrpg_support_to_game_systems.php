<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_systems', function (Blueprint $table) {
            $table->string('type')->default('boardgame')->index()->after('slug');
            $table->string('source')->nullable()->after('bgg_last_synced_at');
            $table->string('source_slug')->nullable()->after('source');
            $table->string('creator')->nullable()->after('source_slug');
            $table->string('player_range')->nullable()->after('creator');
            $table->decimal('sp_rating', 3, 2)->nullable()->after('player_range');
            $table->unsignedInteger('sp_review_count')->nullable()->after('sp_rating');
            $table->text('faq_content')->nullable()->after('sp_review_count');
            $table->text('external_links')->nullable()->after('faq_content');
            $table->text('showcases')->nullable()->after('external_links');
            $table->text('instructions')->nullable()->after('showcases');
        });

        // Data migration: populate type from existing bgg_type
        DB::statement('
            UPDATE game_systems
            SET type = bgg_type
            WHERE bgg_type IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::table('game_systems', function (Blueprint $table) {
            $table->dropColumn([
                'type',
                'source',
                'source_slug',
                'creator',
                'player_range',
                'sp_rating',
                'sp_review_count',
                'faq_content',
                'external_links',
                'showcases',
                'instructions',
            ]);
        });
    }
};
