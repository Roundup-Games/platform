<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_system_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['boardgame', 'ttrpg', 'other'])->default('boardgame');
            $table->string('bgg_url')->nullable();
            $table->string('publisher')->nullable();
            $table->string('designer')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'in_review', 'approved', 'rejected', 'duplicate'])->default('pending');
            $table->foreignId('game_system_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_system_requests');
    }
};
