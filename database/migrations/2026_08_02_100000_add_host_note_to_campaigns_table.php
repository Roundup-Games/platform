<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Optional welcoming message from the host, shown to players on the
            // campaign detail page. Used by the Gathering campaign flow
            // (GameType::Gathering) — a recurring board-game night or casual
            // meet-up — mirroring the games.host_note column. Nullable because
            // legacy campaigns and non-gathering types have no host note. Plain
            // text with no cast, mirroring the games.host_note precedent.
            $table->text('host_note')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('host_note');
        });
    }
};
