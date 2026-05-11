<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_participants', function (Blueprint $table) {
            $table->timestamp('waitlisted_at')->nullable()->after('benched_at');
            $table->timestamp('confirmation_expires_at')->nullable()->after('waitlisted_at');
            $table->unsignedInteger('confirmation_attempts')->nullable()->after('confirmation_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_participants', function (Blueprint $table) {
            $table->dropColumn(['confirmation_attempts', 'confirmation_expires_at', 'waitlisted_at']);
        });
    }
};
