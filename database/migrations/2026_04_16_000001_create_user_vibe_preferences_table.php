<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_vibe_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('vibe_preference_value');
            $table->enum('preference_type', ['favorite', 'avoid']);
            $table->primary(['user_id', 'vibe_preference_value']);

            $table->index(['user_id', 'preference_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_vibe_preferences');
    }
};
