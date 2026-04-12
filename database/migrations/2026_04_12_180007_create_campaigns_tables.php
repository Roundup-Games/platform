<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('game_system_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description');
            $table->json('images')->nullable();
            $table->enum('recurrence', ['weekly', 'bi-weekly', 'monthly']);
            $table->string('time_of_day');
            $table->float('session_duration'); // hours
            $table->float('price_per_session')->nullable();
            $table->string('language');
            $table->json('location')->nullable();
            $table->enum('status', ['active', 'cancelled', 'completed'])->default('active');
            $table->json('minimum_requirements')->nullable();
            $table->enum('visibility', ['public', 'protected', 'private'])->default('public');
            $table->json('safety_rules')->nullable();
            $table->timestamps();
        });

        Schema::create('campaign_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'player', 'invited', 'applicant'])->default('player');
            $table->enum('status', ['approved', 'rejected', 'pending'])->default('pending');

            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->unique(['campaign_id', 'user_id']);
        });

        Schema::create('campaign_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('campaign_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('message')->nullable();
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->unique(['campaign_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_applications');
        Schema::dropIfExists('campaign_participants');
        Schema::dropIfExists('campaigns');
    }
};
