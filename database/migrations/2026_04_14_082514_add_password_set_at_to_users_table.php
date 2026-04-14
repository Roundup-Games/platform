<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_set_at')->nullable()->after('password');
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Set passwords for any OAuth-only users before making column NOT NULL
        // They'll need to reset their password via OAuth flow after rollback
        DB::table('users')->whereNull('password')->update([
            'password' => Hash::make(Str::random(32)),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_set_at');
            $table->string('password')->nullable(false)->change();
        });
    }
};
