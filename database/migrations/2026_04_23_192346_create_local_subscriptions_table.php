<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_type_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active'); // active, canceled, expired
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'membership_type_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_subscriptions');
    }
};
