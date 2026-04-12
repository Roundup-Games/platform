<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('type');
            $table->string('paddle_id');
            $table->string('status');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'paddle_id']);
        });

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id');
            $table->string('product_id');
            $table->string('price_id');
            $table->integer('quantity')->default(1);
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('subscription_item_id')->nullable();
            $table->string('paddle_id');
            $table->string('status');
            $table->string('customer_id')->nullable();
            $table->string('product_id')->nullable();
            $table->string('price_id')->nullable();
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('tax')->default(0);
            $table->timestamp('billed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
    }
};
