<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Membership types are managed via Paddle products, but we keep a local
        // reference for display purposes and admin configuration.
        Schema::create('membership_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description', 1000)->nullable();
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('duration_months');
            $table->string('status', 50)->default('active');
            $table->string('paddle_price_id')->nullable(); // links to Paddle price
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_types');
    }
};
