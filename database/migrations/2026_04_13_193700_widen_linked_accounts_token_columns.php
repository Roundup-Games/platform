<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen token columns on linked_accounts from varchar(255) to text.
     *
     * Google OAuth access tokens can exceed 255 characters, causing
     * "value too long for type character varying(255)" on insert.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE linked_accounts ALTER COLUMN token TYPE text');
            DB::statement('ALTER TABLE linked_accounts ALTER COLUMN refresh_token TYPE text');
        } else {
            Schema::table('linked_accounts', function (Blueprint $table) {
                $table->text('token')->nullable()->change();
                $table->text('refresh_token')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE linked_accounts ALTER COLUMN token TYPE varchar(255)');
            DB::statement('ALTER TABLE linked_accounts ALTER COLUMN refresh_token TYPE varchar(255)');
        } else {
            Schema::table('linked_accounts', function (Blueprint $table) {
                $table->string('token')->nullable()->change();
                $table->string('refresh_token')->nullable()->change();
            });
        }
    }
};
