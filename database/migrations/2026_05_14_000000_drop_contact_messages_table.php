<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('contact_messages');
    }

    public function down(): void
    {
        // Recreating the table is handled by the original create migration.
        // Since that migration has been deleted, there is no down path.
    }
};
