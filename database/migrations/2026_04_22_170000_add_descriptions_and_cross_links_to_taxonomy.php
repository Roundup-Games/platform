<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add description columns to taxonomy tables
        Schema::table('game_system_categories', function (Blueprint $table) {
            $table->text('description')->nullable()->after('slug');
        });

        Schema::table('game_system_mechanics', function (Blueprint $table) {
            $table->text('description')->nullable()->after('slug');
        });

        // Self-referencing M:N pivot for category cross-links (e.g. 'similar' from SP data)
        Schema::create('game_system_category_relations', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained('game_system_categories')->cascadeOnDelete();
            $table->foreignId('related_category_id')->constrained('game_system_categories')->cascadeOnDelete();
            $table->string('type')->nullable()->default('similar');
            $table->primary(['category_id', 'related_category_id']);
        });

        // Self-referencing M:N pivot for mechanic cross-links (e.g. 'similar' from SP data)
        Schema::create('game_system_mechanic_relations', function (Blueprint $table) {
            $table->foreignId('mechanic_id')->constrained('game_system_mechanics')->cascadeOnDelete();
            $table->foreignId('related_mechanic_id')->constrained('game_system_mechanics')->cascadeOnDelete();
            $table->string('type')->nullable()->default('similar');
            $table->primary(['mechanic_id', 'related_mechanic_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_system_mechanic_relations');
        Schema::dropIfExists('game_system_category_relations');

        Schema::table('game_system_mechanics', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('game_system_categories', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
