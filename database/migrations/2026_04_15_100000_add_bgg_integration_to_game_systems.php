<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add BGG-specific columns to game_systems
        Schema::table('game_systems', function (Blueprint $table) {
            $table->unsignedInteger('bgg_id')->unique()->nullable()->after('year_released');
            $table->string('bgg_type')->nullable()->after('bgg_id');
            $table->string('thumbnail_url')->nullable()->after('bgg_type');
            $table->foreignId('base_game_id')->nullable()->after('thumbnail_url')
                ->constrained('game_systems')->nullOnDelete();
            $table->decimal('bgg_average_rating', 4, 2)->nullable()->after('base_game_id');
            $table->decimal('bgg_bayes_average', 4, 2)->nullable()->after('bgg_average_rating');
            $table->unsignedInteger('bgg_rank')->nullable()->after('bgg_bayes_average');
            $table->unsignedInteger('bgg_users_rated')->nullable()->after('bgg_rank');
            $table->decimal('bgg_average_weight', 4, 2)->nullable()->after('bgg_users_rated');
            $table->timestamp('bgg_last_synced_at')->nullable()->after('bgg_average_weight');
        });

        // 2. Taxonomy: families
        Schema::create('game_system_families', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        // Junction: game_system ↔ family
        Schema::create('game_system_family', function (Blueprint $table) {
            $table->foreignId('game_system_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_system_family_id')->constrained('game_system_families')->cascadeOnDelete();
            $table->primary(['game_system_id', 'game_system_family_id']);
        });

        // 3. Taxonomy: designers
        Schema::create('game_system_designers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        // Junction: game_system ↔ designer
        Schema::create('game_system_designer', function (Blueprint $table) {
            $table->foreignId('game_system_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_system_designer_id')->constrained('game_system_designers')->cascadeOnDelete();
            $table->primary(['game_system_id', 'game_system_designer_id']);
        });

        // 4. Taxonomy: publishers
        Schema::create('game_system_publishers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        // Junction: game_system ↔ publisher
        Schema::create('game_system_publisher', function (Blueprint $table) {
            $table->foreignId('game_system_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_system_publisher_id')->constrained('game_system_publishers')->cascadeOnDelete();
            $table->primary(['game_system_id', 'game_system_publisher_id']);
        });

        // 5. BGG sync logs
        Schema::create('bgg_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_system_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status'); // running, success, failed
            $table->json('bgg_ids')->nullable();
            $table->integer('items_synced')->default(0);
            $table->integer('items_failed')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bgg_sync_logs');
        Schema::dropIfExists('game_system_publisher');
        Schema::dropIfExists('game_system_publishers');
        Schema::dropIfExists('game_system_designer');
        Schema::dropIfExists('game_system_designers');
        Schema::dropIfExists('game_system_family');
        Schema::dropIfExists('game_system_families');

        Schema::table('game_systems', function (Blueprint $table) {
            $table->dropForeign(['base_game_id']);
            $table->dropColumn([
                'bgg_id',
                'bgg_type',
                'thumbnail_url',
                'base_game_id',
                'bgg_average_rating',
                'bgg_bayes_average',
                'bgg_rank',
                'bgg_users_rated',
                'bgg_average_weight',
                'bgg_last_synced_at',
            ]);
        });
    }
};
