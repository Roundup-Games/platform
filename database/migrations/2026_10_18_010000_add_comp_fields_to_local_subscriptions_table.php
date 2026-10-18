<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comp provenance + ops fields on local_subscriptions (M069 S03).
 *
 * Zero behavior change for existing data is the point: every current
 * write path (GmRoleService::activateGmSubscription, DemoSeedCommand)
 * inserts self-serve-shaped rows with ends_at = null, so the NOT NULL
 * DEFAULT 'self_serve' backfill classifies them all as self_serve.
 *
 *  - source: 'self_serve' | 'comp' (cast to LocalSubscriptionSource in
 *    the model). Postgres backfills existing rows via the column default.
 *  - granted_by: admin who granted a comp; nulled when that account is
 *    deleted (badge_events keeps the human-readable audit trail).
 *  - reason: required at comp-grant time (enforced by the grant action,
 *    not the schema — nullable like badge_user.revoked_reason).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_subscriptions', function (Blueprint $table): void {
            $table->string('source')->default('self_serve')->after('canceled_at');
            $table->foreignUuid('granted_by')->nullable()->after('source')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('reason')->nullable()->after('granted_by');
        });
    }

    public function down(): void
    {
        Schema::table('local_subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('granted_by');
            $table->dropColumn(['source', 'reason']);
        });
    }
};
