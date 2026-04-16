<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 3)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('place_id')->nullable();
            $table->string('source', 50)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Composite index for bounding box queries (D037: WHERE lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?)
            $table->index(['latitude', 'longitude'], 'locations_lat_lng_index');

            // Index for deduplication lookups by place_id (Google Places ID, etc.)
            $table->index('place_id', 'locations_place_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
