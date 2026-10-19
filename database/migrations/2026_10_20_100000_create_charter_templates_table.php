<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved charter templates (M070/S04, R085 — member convenience): a GM
 * refines their standard charter once and reuses it across campaigns.
 *
 * One row per saved template, owned by the user (cascade-deleted with
 * them). content is a CharterContent::fromArray-normalized snapshot in
 * the schema the source charter carried, stamped content_schema —
 * tolerant rendering keeps old templates usable when the schema bumps
 * (concept §6). Templates are convenience data, not history: rows are
 * replaced on delete/re-save, never versioned (unlike
 * session_zero_survey_versions).
 *
 * user_id index: CharterTemplateService::listFor() orders the owner's
 * templates by name; the lookup is always per-owner, never global.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charter_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 255);
            // Normalized charter snapshot (schema-v1 section keys locked
            // by the CharterContent DTO).
            $table->jsonb('content');
            $table->unsignedInteger('content_schema')->default(1);
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charter_templates');
    }
};
