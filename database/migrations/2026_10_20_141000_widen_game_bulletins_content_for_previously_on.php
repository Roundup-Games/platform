<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen game_bulletins.content to text for the Previously On digest
 * (M071/S04, docs/spec/campaign-journal-concept.md §3.2).
 *
 * The column was varchar(280) — the host bulletin board's quick-note
 * ceiling, enforced by the Livewire form (max:280, unchanged). The
 * Previously On digest is an application-composed bulletin carrying a
 * 200-word recap excerpt plus goals/threads/CTA and cannot fit 280
 * characters; the concept pins the bulletin board as the canonical
 * persistent channel, so the storage must permit the composed body.
 *
 * Backwards compatible: every existing row satisfies text, no index or
 * constraint touches content, and downstream consumers already bound the
 * length themselves (mail preview Str::limit(100), Discord teaser snippet
 * 100, board form 280 — host-typed quick notes keep their validation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_bulletins', function (Blueprint $table): void {
            $table->text('content')->change();
        });
    }

    public function down(): void
    {
        Schema::table('game_bulletins', function (Blueprint $table): void {
            $table->string('content', 280)->change();
        });
    }
};
