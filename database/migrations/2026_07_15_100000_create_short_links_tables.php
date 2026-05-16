<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_links', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->text('url');
            $table->string('linkable_type')->nullable();
            $table->string('linkable_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('label', 100)->nullable();
            $table->string('purpose', 50)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_hits')->nullable();
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['linkable_type', 'linkable_id']);
            $table->index('expires_at');
            $table->index('user_id');
        });

        Schema::create('short_link_hits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('short_link_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address', 128)->nullable();
            $table->text('referer')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->timestamp('hit_at');

            $table->index(['short_link_id', 'hit_at']);
            $table->index('hit_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_link_hits');
        Schema::dropIfExists('short_links');
    }
};
