<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the game_system_requests table.
     *
     * Game system requests are now managed through Escalated tickets
     * with ticket_type='game_system_request' and metadata JSON for
     * custom fields (bgg_url, publisher, designer, etc.).
     */
    public function up(): void
    {
        Schema::dropIfExists('game_system_requests');
    }

    /**
     * Reverse the migrations.
     *
     * No-op: recreating the table is handled by the original create migration
     * which has been deleted. The table was already dropped by this point
     * and all data is in Escalated tickets.
     */
    public function down(): void
    {
        // Intentionally left empty.
        // The original create_game_system_requests_table migration has been
        // removed. Restoring the table would require re-running that migration.
    }
};
