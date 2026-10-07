<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a nullable created_at column to Spatie's model_has_roles pivot
 * (M063/S04 co-organizer delegation).
 *
 * Spatie does not timestamp role assignments, but the co-organizer
 * assignment IS the delegation record, and the ManageEvent Team tab
 * shows when delegation happened ("granted-at"). The column is stamped
 * explicitly by EventDelegationService::grantCoOrganizer() after a NEW
 * assignment: duplicate grants keep the original grant date, and rows
 * predating this column read as null and render as "—".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->dropColumn('created_at');
        });
    }
};
