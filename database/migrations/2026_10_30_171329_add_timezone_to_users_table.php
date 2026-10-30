<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a nullable per-user IANA timezone identifier to users (R089).
     *
     * Captured from the login device (Intl.DateTimeFormat().resolvedOptions()
     * .timeZone posted by the login form) and consumed wherever wall-clock
     * times are rendered for that user (SessionReminder first, MEM220's
     * null-coalesce hook). Storage contract:
     *
     * - IANA identifier ONLY ('Europe/Berlin'), never a UTC offset —
     *   offsets lose DST and would drift half the year.
     * - null means "not captured yet" — no backfill; the DACH default
     *   ('Europe/Berlin') keeps applying at render time.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('timezone', 64)->nullable()->after('preferred_language');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
