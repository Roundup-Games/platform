<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('is_verified')->default(false)->index();
            $table->string('venue_type', 50)->nullable();
            $table->text('venue_notes')->nullable();
            $table->string('website_url', 500)->nullable();
            $table->uuid('managed_by')->nullable();
            $table->json('venue_metadata')->nullable();

            $table->foreign('managed_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropForeign(['managed_by']);

            $table->dropColumn([
                'is_verified',
                'venue_type',
                'venue_notes',
                'website_url',
                'managed_by',
                'venue_metadata',
            ]);
        });
    }
};
