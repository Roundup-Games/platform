<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // Optional welcoming message from the host, shown to players on
            // the game detail page. Used by the Gathering creation flow
            // (GameType::Gathering). Nullable because legacy games and
            // non-gathering types have no host note. Plain string - no cast,
            // mirroring the location_instructions precedent.
            $table->text('host_note')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('host_note');
        });
    }
};
