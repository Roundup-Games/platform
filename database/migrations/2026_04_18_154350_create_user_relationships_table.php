<?php

use App\Enums\RelationshipType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', RelationshipType::values());

            $table->unique(['user_id', 'related_user_id', 'type']);
            $table->index(['user_id', 'type']);
            $table->index(['related_user_id', 'type']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_relationships');
    }
};
