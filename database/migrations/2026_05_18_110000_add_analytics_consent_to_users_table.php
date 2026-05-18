<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store analytics consent decision on the user record.
 *
 * The PostHogConsentChecker reads from the cookie_consent cookie during
 * request cycles. However, when UserAnonymizationService runs from a
 * non-request context (artisan command, queued job), the cookie is
 * unavailable. This column persists the consent decision so that
 * PostHog data deletion can be correctly dispatched during anonymization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('analytics_consent')->default(false)->after('gender_consent');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('analytics_consent');
        });
    }
};
