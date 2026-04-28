<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('session_debriefings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('game_id');
            $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('tool_type', ['debriefing', 'stars-and-wishes']);
            $table->json('responses')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->unique(['game_id', 'user_id']);
            $table->index(['tool_type']);
            $table->index(['submitted_at']);
            $table->timestamps();
        });

        Log::info('Created session_debriefings table.');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('session_debriefings');
    }
};
