<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_zero_confirmations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('session_zero_survey_id');
            $table->foreign('session_zero_survey_id')
                ->references('id')
                ->on('session_zero_surveys')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('confirmed_at')->nullable();

            $table->unique(['session_zero_survey_id', 'user_id']);

            $table->index('session_zero_survey_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_zero_confirmations');
    }
};
