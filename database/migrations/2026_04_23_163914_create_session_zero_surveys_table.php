<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_zero_surveys', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('gm_profile_id');
            $table->foreign('gm_profile_id')
                ->references('id')
                ->on('gm_profiles')
                ->cascadeOnDelete();

            $table->uuid('game_id')->nullable();
            $table->foreign('game_id')
                ->references('id')
                ->on('games')
                ->nullOnDelete();

            $table->string('title');
            $table->json('content')->nullable();
            $table->string('uuid')->unique();
            $table->string('status')->default('active');
            $table->unsignedInteger('confirmation_count')->default(0);

            $table->timestamps();

            $table->index('gm_profile_id');
            $table->index('game_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_zero_surveys');
    }
};
