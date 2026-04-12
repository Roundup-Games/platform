<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('campaign_id')->nullable();
            $table->foreignId('game_system_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamp('date_time');
            $table->text('description');
            $table->float('expected_duration'); // hours
            $table->float('price')->nullable();
            $table->string('language', 50);
            $table->json('location'); // {address, lat, lng, placeId}
            $table->enum('status', ['scheduled', 'canceled', 'completed'])->default('scheduled');
            $table->json('minimum_requirements')->nullable();
            $table->enum('visibility', ['public', 'protected', 'private'])->default('public');
            $table->json('safety_rules')->nullable();
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('campaigns')->nullOnDelete();
        });

        Schema::create('game_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('game_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'player', 'invited', 'applicant'])->default('player');
            $table->enum('status', ['approved', 'rejected', 'pending'])->default('pending');

            $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
            $table->unique(['game_id', 'user_id']);
        });

        Schema::create('game_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('game_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('message')->nullable();
            $table->timestamps();

            $table->foreign('game_id')->references('id')->on('games')->cascadeOnDelete();
            $table->unique(['game_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_applications');
        Schema::dropIfExists('game_participants');
        Schema::dropIfExists('games');
    }
};
