<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the existing morphs index and columns, then re-create with UUID-compatible types
        Schema::table('seo', function (Blueprint $table) {
            $table->dropIndex(['model_type', 'model_id']);
            $table->string('model_id', 36)->nullable()->change();
        });

        // Re-add the index after column type change
        Schema::table('seo', function (Blueprint $table) {
            $table->index(['model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::table('seo', function (Blueprint $table) {
            $table->dropIndex(['model_type', 'model_id']);
        });

        // Truncate non-integer model_ids before reverting column type
        DB::table('seo')->whereRaw("model_id !~ '^\d+$'")->delete();

        Schema::table('seo', function (Blueprint $table) {
            $table->unsignedBigInteger('model_id')->nullable()->change();
        });

        Schema::table('seo', function (Blueprint $table) {
            $table->index(['model_type', 'model_id']);
        });
    }
};
