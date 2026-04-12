<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 3)->nullable(); // ISO 3166-1 alpha-3
            $table->string('logo_url')->nullable();
            $table->string('primary_color', 7)->nullable(); // hex
            $table->string('secondary_color', 7)->nullable();
            $table->string('founded_year', 4)->nullable();
            $table->string('website')->nullable();
            $table->json('social_links')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['captain', 'coach', 'player', 'substitute'])->default('player');
            $table->enum('status', ['pending', 'active', 'inactive', 'removed'])->default('pending');
            $table->string('jersey_number', 3)->nullable();
            $table->string('position', 50)->nullable();
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users');
            $table->text('notes')->nullable();

            // Only one active membership per user
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
    }
};
