<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Game systems catalog (board games, TTRPGs, etc.)
        Schema::create('game_systems', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->json('images')->nullable();
            $table->unsignedInteger('min_players')->nullable();
            $table->unsignedInteger('max_players')->nullable();
            $table->unsignedInteger('optimal_players')->nullable();
            $table->unsignedInteger('average_play_time')->nullable(); // minutes
            $table->string('age_rating', 50)->nullable();
            $table->string('complexity_rating', 50)->nullable();
            $table->unsignedInteger('year_released')->nullable();
            $table->timestamps();
        });

        // Taxonomy: categories (e.g. Strategy, Party, RPG)
        Schema::create('game_system_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        // Taxonomy: mechanics (e.g. Deck Building, Worker Placement)
        Schema::create('game_system_mechanics', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        // Junction: game_system ↔ category
        Schema::create('game_system_category', function (Blueprint $table) {
            $table->foreignId('game_system_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_system_category_id')->constrained()->cascadeOnDelete();
            $table->primary(['game_system_id', 'game_system_category_id']);
        });

        // Junction: game_system ↔ mechanic
        Schema::create('game_system_mechanic', function (Blueprint $table) {
            $table->foreignId('game_system_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_system_mechanic_id')->constrained()->cascadeOnDelete();
            $table->primary(['game_system_id', 'game_system_mechanic_id']);
        });

        // User preferences (favorite / avoid)
        Schema::create('user_game_system_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_system_id')->constrained()->cascadeOnDelete();
            $table->enum('preference_type', ['favorite', 'avoid']);
            $table->primary(['user_id', 'game_system_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_game_system_preferences');
        Schema::dropIfExists('game_system_mechanic');
        Schema::dropIfExists('game_system_category');
        Schema::dropIfExists('game_system_mechanics');
        Schema::dropIfExists('game_system_categories');
        Schema::dropIfExists('game_systems');
    }
};
